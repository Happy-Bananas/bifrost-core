<?php

declare(strict_types=1);

namespace Bifrost\Core\Tracezilla;

use DateTimeImmutable;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Supplies a valid tracezilla access token using the OAuth Resource Owner
 * Password Credentials grant.
 *
 * The username and password are used once, by the tracezilla:login command.
 * After that the stored refresh token renews the access token well before it
 * expires, so a regularly running integration never needs the password again.
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

        return new self($http, $store, $configuration->clientId, $configuration->clientSecret);
    }

    /** Exchange a username and password for tokens and store them. */
    public function login(string $username, string $password): OAuthTokens
    {
        $tokens = $this->requestTokens([
            'grant_type' => 'password',
            'username' => $username,
            'password' => $password,
        ]);
        $this->store->save($tokens);

        return $tokens;
    }

    /** An access token that is valid for at least RENEW_WITHIN_DAYS more days. */
    public function accessToken(): string
    {
        $tokens = $this->storedTokens();
        if ($this->isFresh($tokens)) {
            return $tokens->accessToken;
        }

        return $this->renewUnlessAnotherRunDid();
    }

    /**
     * Renew the token after tracezilla rejected it, unless another run already
     * replaced the rejected token.
     */
    public function renewAfterRejection(string $rejectedAccessToken): string
    {
        $lock = $this->store->lock();
        try {
            $tokens = $this->storedTokens();
            if ($tokens->accessToken !== $rejectedAccessToken) {
                return $tokens->accessToken;
            }

            return $this->renew($tokens)->accessToken;
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

    public function expiresAt(): DateTimeImmutable
    {
        return $this->storedTokens()->expiresAt;
    }

    private function isFresh(OAuthTokens $tokens): bool
    {
        return ! $tokens->expiresWithin(self::RENEW_WITHIN_DAYS, $this->clock->now());
    }

    private function renewUnlessAnotherRunDid(): string
    {
        $lock = $this->store->lock();
        try {
            // Another run may have renewed the token while we waited for the lock.
            $tokens = $this->storedTokens();
            if (! $this->isFresh($tokens)) {
                $tokens = $this->renew($tokens);
            }

            return $tokens->accessToken;
        } finally {
            $lock->release();
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

    private function storedTokens(): OAuthTokens
    {
        $tokens = $this->store->load();
        if ($tokens === null) {
            throw new RuntimeException('No tracezilla token is stored. Run bin/bifrost-connect tracezilla:login first.');
        }

        return $tokens;
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
            $hint = ' The refresh token was rejected; run bin/bifrost-connect tracezilla:login again.';
        } else {
            $hint = ' Check TRACEZILLA_CLIENT_ID, TRACEZILLA_CLIENT_SECRET, and the username and password.';
        }

        return "tracezilla token request failed with HTTP {$status}: {$body}{$hint}";
    }
}
