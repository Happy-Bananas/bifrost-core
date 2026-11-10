<?php

declare(strict_types=1);

namespace Tests\Unit\Tracezilla;

use DateTimeImmutable;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Bifrost\Core\Tracezilla\OAuthTokens;
use Bifrost\Core\Tracezilla\TokenProvider;
use Bifrost\Core\Tracezilla\TokenStore;

final class TokenProviderTest extends TestCase
{
    private string $directory;
    private TokenStore $store;
    private MockHandler $responses;
    /** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/tz-token-'.bin2hex(random_bytes(4));
        $this->store = new TokenStore($this->directory.'/token.json');
        $this->responses = new MockHandler();
        $this->now = new DateTimeImmutable('2026-10-28T12:00:00+00:00');
    }

    protected function tearDown(): void
    {
        $files = glob($this->directory.'/*');
        if ($files !== false) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
        @rmdir($this->directory);
    }

    public function test_the_first_command_logs_in_with_the_api_user_and_stores_only_tokens(): void
    {
        $this->responses->append($this->tokenResponse('access-1', 'refresh-1'));

        self::assertSame('access-1', $this->provider()->accessToken());

        parse_str((string) $this->history[0]['request']->getBody(), $form);
        self::assertSame('password', $form['grant_type']);
        self::assertSame('client-id', $form['client_id']);
        self::assertSame('api-user@example.test', $form['username']);
        self::assertSame('refresh-1', $this->storedRefreshToken());
        self::assertStringNotContainsString('api-password', (string) file_get_contents($this->store->path()));
        self::assertSame(0600, fileperms($this->store->path()) & 0777);
    }

    public function test_a_fresh_token_is_used_without_contacting_tracezilla(): void
    {
        $this->store->save(new OAuthTokens('access-1', 'refresh-1', $this->now->modify('+31 days')));

        self::assertSame('access-1', $this->provider()->accessToken());
        self::assertSame([], $this->history);
    }

    public function test_a_token_close_to_expiry_is_renewed_and_the_new_refresh_token_stored(): void
    {
        $this->store->save(new OAuthTokens('access-1', 'refresh-1', $this->now->modify('+29 days')));
        $this->responses->append($this->tokenResponse('access-2', 'refresh-2'));

        self::assertSame('access-2', $this->provider()->accessToken());

        parse_str((string) $this->history[0]['request']->getBody(), $form);
        self::assertSame(['refresh_token', 'refresh-1'], [$form['grant_type'], $form['refresh_token']]);
        self::assertSame('refresh-2', $this->storedRefreshToken());
    }

    public function test_a_rejected_token_is_renewed_only_once(): void
    {
        $this->store->save(new OAuthTokens('access-1', 'refresh-1', $this->now->modify('+300 days')));
        $this->responses->append($this->tokenResponse('access-2', 'refresh-2'));
        $provider = $this->provider();

        self::assertSame('access-2', $provider->renewAfterRejection('access-1'));
        // A second caller that also saw access-1 rejected reuses the renewed token.
        self::assertSame('access-2', $provider->renewAfterRejection('access-1'));
        self::assertCount(1, $this->history);
    }

    public function test_a_rejected_refresh_token_leads_to_a_new_login(): void
    {
        $this->store->save(new OAuthTokens('access-1', 'refresh-1', $this->now->modify('+1 day')));
        $this->responses->append(
            new Response(401, [], '{"error":"invalid_grant"}'),
            $this->tokenResponse('access-2', 'refresh-2'),
        );

        self::assertSame('access-2', $this->provider()->accessToken());

        parse_str((string) $this->history[1]['request']->getBody(), $form);
        self::assertSame('password', $form['grant_type']);
        self::assertSame('refresh-2', $this->storedRefreshToken());
    }

    public function test_a_refused_login_names_the_settings_to_check(): void
    {
        $this->responses->append(new Response(400, [], '{"error":"invalid_grant"}'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TRACEZILLA_USERNAME, and TRACEZILLA_PASSWORD in .env');

        $this->provider()->accessToken();
    }

    private function provider(): TokenProvider
    {
        $stack = HandlerStack::create($this->responses);
        $stack->push(Middleware::history($this->history));

        return new TokenProvider(
            new HttpClient(['handler' => $stack, 'base_uri' => 'https://tracezilla.test/']),
            $this->store,
            'client-id',
            'client-secret',
            'api-user@example.test',
            'api-password',
            new FixedClock($this->now),
        );
    }

    private function tokenResponse(string $access, string $refresh): Response
    {
        return new Response(200, [], json_encode([
            'token_type' => 'Bearer',
            'expires_in' => 31536000,
            'access_token' => $access,
            'refresh_token' => $refresh,
        ], JSON_THROW_ON_ERROR));
    }

    private function storedRefreshToken(): ?string
    {
        $tokens = $this->store->load();
        if ($tokens === null) {
            return null;
        }

        return $tokens->refreshToken;
    }
}
