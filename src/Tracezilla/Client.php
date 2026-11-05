<?php

declare(strict_types=1);

namespace Bifrost\Core\Tracezilla;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Calls the tracezilla API for one team, with an access token that renews
 * itself. A request that tracezilla could not handle for the moment is sent
 * again a few times, waiting a little longer each time.
 */
final class Client
{
    /** How many times a request is sent again before the failure is reported. */
    public const RETRIES = 3;

    /** The longest wait before a retry, even if tracezilla asks for more. */
    public const MAX_WAIT_SECONDS = 60;

    /** Answers that mean "try again later" for a request that only reads. */
    private const TEMPORARY_FAILURES = [500, 502, 503, 504];

    private HttpClient $http;
    private Pause $pause;

    public function __construct(
        TracezillaConfiguration $configuration,
        private TokenProvider $tokens,
        ?HttpClient $http = null,
        ?Pause $pause = null,
    ) {
        $this->pause = $pause ?? new SystemPause();
        $this->http = $http ?? new HttpClient([
            'base_uri' => "{$configuration->baseUrl}/api/v1/{$configuration->teamSlug}/",
            'timeout' => $configuration->timeout,
            'connect_timeout' => $configuration->connectTimeout,
            'headers' => ['Accept' => 'application/json'],
        ]);
    }

    /** @return array<string, mixed> */
    public function get(string $path, array $query = []): array
    {
        return $this->send('GET', $path, ['query' => $query]);
    }

    /** @return array<string, mixed> */
    public function post(string $path, array $payload): array
    {
        return $this->send('POST', $path, ['json' => $payload]);
    }

    /** @return array<string, mixed> */
    public function put(string $path, array $payload): array
    {
        return $this->send('PUT', $path, ['json' => $payload]);
    }

    /** @return array<string, mixed> */
    public function delete(string $path): array
    {
        return $this->send('DELETE', $path, []);
    }

    /**
     * Every page of a list, following links.next_page until the last page.
     *
     * @return list<array<string, mixed>>
     */
    public function getAll(string $path, array $query = []): array
    {
        $records = [];
        $visitedPages = [];

        while (true) {
            $page = $this->get($path, $query);
            $records = array_merge($records, $this->recordsOf($page, $path));

            $nextPageQuery = $this->nextPageQuery($page, $path);
            if ($nextPageQuery === null) {
                return $records;
            }

            $query = array_merge($query, $nextPageQuery);
            $pageKey = http_build_query($query);
            if (isset($visitedPages[$pageKey])) {
                throw new RuntimeException("tracezilla returned the same page of {$path} repeatedly.");
            }
            $visitedPages[$pageKey] = true;
        }
    }

    /**
     * @param array<string, mixed> $page
     * @return list<array<string, mixed>>
     */
    private function recordsOf(array $page, string $path): array
    {
        if (! isset($page['data'])) {
            throw new RuntimeException("tracezilla response for {$path} is missing data.");
        }
        if (! is_array($page['data'])) {
            throw new RuntimeException("tracezilla response for {$path} is missing data.");
        }

        return array_values($page['data']);
    }

    /**
     * The query of the next page, or null on the last page.
     *
     * @param array<string, mixed> $page
     * @return array<string, mixed>|null
     */
    private function nextPageQuery(array $page, string $path): ?array
    {
        if (! isset($page['links']['next_page'])) {
            return null;
        }
        $nextPageUrl = $page['links']['next_page'];
        if (! is_string($nextPageUrl) || $nextPageUrl === '') {
            return null;
        }

        parse_str((string) parse_url($nextPageUrl, PHP_URL_QUERY), $query);
        if ($query === []) {
            throw new RuntimeException("tracezilla returned an invalid next-page URL for {$path}.");
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function send(string $method, string $path, array $options): array
    {
        $response = $this->sendWithRenewal($method, $path, $options);
        $body = (string) $response->getBody();
        if ($body === '') {
            return [];
        }

        $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        // Some endpoints, such as DELETE, answer with a bare value.
        if (! is_array($decoded)) {
            return ['data' => $decoded];
        }

        return $decoded;
    }

    /**
     * Send the request. If tracezilla rejects the token (HTTP 401), renew the
     * token once and send the request again.
     */
    private function sendWithRenewal(string $method, string $path, array $options): ResponseInterface
    {
        $token = $this->tokens->accessToken();
        try {
            return $this->requestWithRetries($method, $path, $options, $token);
        } catch (RequestException $exception) {
            if ($this->statusOf($exception) !== 401) {
                throw $this->explainFailure($exception, $method, $path);
            }
        }

        $renewedToken = $this->tokens->renewAfterRejection($token);
        try {
            return $this->requestWithRetries($method, $path, $options, $renewedToken);
        } catch (RequestException $exception) {
            throw $this->explainFailure($exception, $method, $path);
        }
    }

    /**
     * Send the request, and send it again after a pause while tracezilla answers
     * that it cannot handle it for the moment. After RETRIES retries, or for any
     * other failure, the error is passed on.
     */
    private function requestWithRetries(string $method, string $path, array $options, string $token): ResponseInterface
    {
        $retries = 0;
        while (true) {
            try {
                return $this->request($method, $path, $options, $token);
            } catch (ConnectException $exception) {
                if (! $this->mayRetry($method, 0, $retries)) {
                    throw $exception;
                }
                $seconds = $this->secondsBeforeRetry(null, $retries);
                $reason = 'tracezilla could not be reached';
            } catch (RequestException $exception) {
                $status = $this->statusOf($exception);
                if (! $this->mayRetry($method, $status, $retries)) {
                    throw $exception;
                }
                $seconds = $this->secondsBeforeRetry($exception->getResponse(), $retries);
                $reason = "tracezilla answered HTTP {$status}";
            }

            $this->pause->pause($seconds, "{$method} {$path}: {$reason}");
            $retries++;
        }
    }

    /**
     * Whether sending the request again is safe and may help.
     *
     * HTTP 429 (too many requests) means tracezilla did not handle the request,
     * so any request may be sent again. For other failures, and when no answer
     * arrived (status 0), only a GET is sent again: a write may already have
     * been carried out, and sending it twice could create a record twice.
     */
    private function mayRetry(string $method, int $status, int $retries): bool
    {
        if ($retries >= self::RETRIES) {
            return false;
        }
        if ($status === 429) {
            return true;
        }
        if ($method !== 'GET') {
            return false;
        }
        if ($status === 0) {
            return true;
        }

        return in_array($status, self::TEMPORARY_FAILURES, true);
    }

    /**
     * How long to wait: what tracezilla asks for in its Retry-After header, or
     * else 1, 2 and then 4 seconds. Never more than MAX_WAIT_SECONDS.
     */
    private function secondsBeforeRetry(?ResponseInterface $response, int $retries): int
    {
        $seconds = 2 ** $retries;
        $requested = $this->retryAfterSeconds($response);
        if ($requested !== null) {
            $seconds = $requested;
        }

        return min($seconds, self::MAX_WAIT_SECONDS);
    }

    /** The whole number of seconds in the Retry-After header, or null. */
    private function retryAfterSeconds(?ResponseInterface $response): ?int
    {
        if ($response === null) {
            return null;
        }
        $header = trim($response->getHeaderLine('Retry-After'));
        if (! ctype_digit($header)) {
            return null;
        }

        return (int) $header;
    }

    private function request(string $method, string $path, array $options, string $token): ResponseInterface
    {
        $options['headers'] = ['Authorization' => "Bearer {$token}"];

        return $this->http->request($method, ltrim($path, '/'), $options);
    }

    /** The HTTP status of a failed request, or 0 if no answer arrived. */
    private function statusOf(RequestException $exception): int
    {
        $response = $exception->getResponse();
        if ($response === null) {
            return 0;
        }

        return $response->getStatusCode();
    }

    /**
     * An error that includes tracezilla's own explanation. Validation errors
     * are explained in the response body, which Guzzle's message cuts short.
     */
    private function explainFailure(RequestException $exception, string $method, string $path): RuntimeException
    {
        $explanation = $exception->getMessage();
        $response = $exception->getResponse();
        if ($response !== null) {
            $body = (string) $response->getBody();
            if ($body !== '') {
                $explanation = mb_substr($body, 0, 1000);
            }
        }
        $status = $this->statusOf($exception);

        return new RuntimeException("{$method} {$path} failed with HTTP {$status}: {$explanation}", previous: $exception);
    }
}
