<?php

declare(strict_types=1);

namespace Bifrost\Core\Tracezilla;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Calls the tracezilla API for one team, with an access token that renews
 * itself.
 */
final class Client
{
    private HttpClient $http;

    public function __construct(
        TracezillaConfiguration $configuration,
        private TokenProvider $tokens,
        ?HttpClient $http = null,
    ) {
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
            return $this->request($method, $path, $options, $token);
        } catch (RequestException $exception) {
            if ($this->statusOf($exception) !== 401) {
                throw $this->explainFailure($exception, $method, $path);
            }
        }

        $renewedToken = $this->tokens->renewAfterRejection($token);
        try {
            return $this->request($method, $path, $options, $renewedToken);
        } catch (RequestException $exception) {
            throw $this->explainFailure($exception, $method, $path);
        }
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
