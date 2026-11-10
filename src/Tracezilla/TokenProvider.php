<?php

declare(strict_types=1);

namespace Bifrost\Core\Tracezilla;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Supplies a valid tracezilla access token using the OAuth Resource Owner
 * Password Credentials grant, with the API user from .env.
 *
 * The first command logs in and stores the tokens. Later commands use the
 * stored token and renew it well before it expires. If tracezilla refuses the
 * renewal, or the token file is gone, the integration simply logs in again, so
 * nobody ever has to type a username or password.
 */
final readonly class TokenProvider
{
    public const RENEW_WITHIN_DAYS = 30;

    private Clock $clock;

    public function __construct(
        private HttpClient $http,
        private TokenStore $store,
        private string $clientId,
        private string $clientSecret,
        private string $username,
        private string $password,
        ?Clock $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
    }

    public static function fromConfiguration(TracezillaConfiguration $configuration): self
    {
        $http = new HttpClient([
            'base_uri' => "{$configuration->baseUrl}/",
            'timeout' => $configuration->timeout,
            'connect_timeout' => $configuration->connectTimeout,
            'headers' => ['Accept' => 'application/json'],
        ]);
        $store = new TokenStore($configuration->tokenFile);

        return new self(
            $http,
            $store,
            $configuration->clientId,
            $configuration->clientSecret,
            $configuration->username,
            $configuration->password,
        );
    }

    /** An access token that is valid for at least RENEW_WITHIN_DAYS more days. */
    public function accessToken(): string
    {
        $tokens = $this->store->load();
        if ($tokens !== null && $this->isFresh($tokens)) {
            return $tokens->accessToken;
        }

        return $this->freshTokenUnlessAnotherRunGotOne();
    }

    /**
     * Renew the token after tracezilla rejected it, unless another run already
     * replaced the rejected token.
     */
    public function renewAfterRejection(string $rejectedAccessToken): string
    {
        $lock = $this->store->lock();
        try {
            $tokens = $this->store->load();
            if ($tokens === null) {
                return $this->logIn()->accessToken;
            }
            if ($tokens->accessToken !== $rejectedAccessToken) {
                return $tokens->accessToken;
            }

            return $this->renewOrLogIn($tokens)->accessToken;
        } finally {
            $lock->release();
        }
    }

    /** The tracezilla user ID the token belongs to (the JWT "sub" claim). */
    public function userId(): int
    {
        $claims = $this->claimsOf($this->accessToken());
        if (! isset($claims['sub'])) {
            throw new RuntimeException('The tracezilla access token does not identify a user.');
        }
        if (! is_numeric($claims['sub'])) {
            throw new RuntimeException('The tracezilla access token does not identify a user.');
        }

        return (int) $claims['sub'];
    }

    private function isFresh(OAuthTokens $tokens): bool
    {
        return ! $tokens->expiresWithin(self::RENEW_WITHIN_DAYS, $this->clock->now());
    }

    private function freshTokenUnlessAnotherRunGotOne(): string
    {
        $lock = $this->store->lock();
        try {
            // Another run may have logged in or renewed while we waited for the lock.
            $tokens = $this->store->load();
            if ($tokens === null) {
                $tokens = $this->logIn();
            } elseif (! $this->isFresh($tokens)) {
                $tokens = $this->renewOrLogIn($tokens);
            }

            return $tokens->accessToken;
        } finally {
            $lock->release();
        }
    }

    /** Log in with the API user from .env and store the tokens. */
    private function logIn(): OAuthTokens
    {
        $tokens = $this->requestTokens([
            'grant_type' => 'password',
            'username' => $this->username,
            'password' => $this->password,
        ]);
        $this->store->save($tokens);

        return $tokens;
    }

    /**
     * Renew with the refresh token. If tracezilla refuses it, for example after
     * the token was revoked, log in again instead.
     */
    private function renewOrLogIn(OAuthTokens $tokens): OAuthTokens
    {
        try {
            return $this->renew($tokens);
        } catch (RuntimeException) {
            return $this->logIn();
        }
    }

    private function renew(OAuthTokens $tokens): OAuthTokens
    {
        $renewed = $this->requestTokens([
            'grant_type' => 'refresh_token',
            'refresh_token' => $tokens->refreshToken,
        ]);
        // A refresh token can be used only once, so store the new one at once.
        $this->store->save($renewed);

        return $renewed;
    }

    /**
     * The claims inside a JWT access token: its middle part, base64url encoded.
     *
     * @return array<string, mixed>
     */
    private function claimsOf(string $accessToken): array
    {
        $parts = explode('.', $accessToken);
        if (count($parts) !== 3) {
            return [];
        }
        $json = base64_decode(strtr($parts[1], '-_', '+/'));
        $claims = json_decode((string) $json, true);
        if (! is_array($claims)) {
            return [];
        }

        return $claims;
    }

    /** @param array<string, string> $grant */
    private function requestTokens(array $grant): OAuthTokens
    {
        $form = $grant;
        $form['client_id'] = $this->clientId;
        $form['client_secret'] = $this->clientSecret;

        $response = $this->sendTokenRequest($form);
        $answer = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        return OAuthTokens::fromTokenResponse($answer, $this->clock->now());
    }

    /** @param array<string, string> $form */
    private function sendTokenRequest(array $form): ResponseInterface
    {
        try {
            return $this->http->post('oauth/token', ['form_params' => $form]);
        } catch (RequestException $exception) {
            $message = $this->explainRejection($exception, $form['grant_type']);
            throw new RuntimeException($message, previous: $exception);
        } catch (GuzzleException $exception) {
            throw new RuntimeException('tracezilla token request failed: '.$exception->getMessage(), previous: $exception);
        }
    }

    private function explainRejection(RequestException $exception, string $grantType): string
    {
        $status = 0;
        $body = '';
        $response = $exception->getResponse();
        if ($response !== null) {
            $status = $response->getStatusCode();
            $body = mb_substr((string) $response->getBody(), 0, 500);
        }

        if ($grantType === 'refresh_token') {
            $hint = ' The refresh token was rejected.';
        } else {
            $hint = ' Check TRACEZILLA_CLIENT_ID, TRACEZILLA_CLIENT_SECRET, TRACEZILLA_USERNAME, and TRACEZILLA_PASSWORD in .env.';
        }

        return "tracezilla token request failed with HTTP {$status}: {$body}{$hint}";
    }
}
