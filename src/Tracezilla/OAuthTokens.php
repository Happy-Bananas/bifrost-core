<?php

declare(strict_types=1);

namespace Bifrost\Core\Tracezilla;

use DateTimeImmutable;
use RuntimeException;

/**
 * The tokens tracezilla hands out after a login: the access token sent with
 * every request, the refresh token that renews it, and when it expires.
 */
final readonly class OAuthTokens
{
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public DateTimeImmutable $expiresAt,
    ) {
    }

    /** Build tokens from a /oauth/token response. */
    public static function fromTokenResponse(array $response, DateTimeImmutable $now): self
    {
        $accessToken = self::textOrNull($response, 'access_token');
        $refreshToken = self::textOrNull($response, 'refresh_token');
        $secondsValid = self::wholeNumberOrNull($response, 'expires_in');
        if ($accessToken === null || $refreshToken === null || $secondsValid === null) {
            throw new RuntimeException('tracezilla token response is missing access_token, refresh_token, or expires_in.');
        }

        $expiresAt = $now->modify("+{$secondsValid} seconds");

        return new self($accessToken, $refreshToken, $expiresAt);
    }

    /** Build tokens from what toArray() stored in the token file. */
    public static function fromArray(array $data): self
    {
        $accessToken = self::textOrNull($data, 'access_token');
        $refreshToken = self::textOrNull($data, 'refresh_token');
        $expiresAt = self::textOrNull($data, 'expires_at');
        if ($accessToken === null || $refreshToken === null || $expiresAt === null) {
            throw new RuntimeException('The stored tracezilla token is incomplete. Delete the token file; the next command logs in again with the credentials in .env.');
        }

        return new self($accessToken, $refreshToken, new DateTimeImmutable($expiresAt));
    }

    /** @return array{access_token: string, refresh_token: string, expires_at: string} */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'expires_at' => $this->expiresAt->format(DATE_ATOM),
        ];
    }

    public function expiresWithin(int $days, DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now->modify("+{$days} days");
    }

    /** The value under $key if it is non-empty text, otherwise null. */
    private static function textOrNull(array $values, string $key): ?string
    {
        if (! isset($values[$key])) {
            return null;
        }
        if (! is_string($values[$key])) {
            return null;
        }
        if ($values[$key] === '') {
            return null;
        }

        return $values[$key];
    }

    /** The value under $key if it is a whole number, otherwise null. */
    private static function wholeNumberOrNull(array $values, string $key): ?int
    {
        if (! isset($values[$key])) {
            return null;
        }
        if (! is_int($values[$key])) {
            return null;
        }

        return $values[$key];
    }
}
