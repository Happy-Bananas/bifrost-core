<?php

declare(strict_types=1);

namespace Tests\Unit\Tracezilla;

use Bifrost\Core\Tracezilla\OAuthTokens;
use Bifrost\Core\Tracezilla\TokenStore;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TokenStoreTest extends TestCase
{
    private TemporaryDirectory $directory;
    private TokenStore $store;

    protected function setUp(): void
    {
        $this->directory = new TemporaryDirectory();
        $this->store = new TokenStore($this->directory->path.'/var/tracezilla-token.json');
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    public function test_nothing_is_stored_before_the_first_login(): void
    {
        self::assertNull($this->store->load());
    }

    public function test_saved_tokens_are_loaded_again(): void
    {
        $this->store->save(new OAuthTokens('access-1', 'refresh-1', new DateTimeImmutable('2027-10-28T12:00:00+00:00')));

        $tokens = $this->store->load();

        self::assertNotNull($tokens);
        self::assertSame('access-1', $tokens->accessToken);
        self::assertSame('refresh-1', $tokens->refreshToken);
        self::assertSame('2027-10-28', $tokens->expiresAt->format('Y-m-d'));
    }

    public function test_only_the_owner_can_read_the_tokens(): void
    {
        $this->store->save(new OAuthTokens('access-1', 'refresh-1', new DateTimeImmutable('+300 days')));
        $lock = $this->store->lock();
        $lock->release();

        self::assertSame(0700, $this->permissions(dirname($this->store->path())));
        self::assertSame(0600, $this->permissions($this->store->path()));
        self::assertSame(0600, $this->permissions($this->store->path().'.lock'));
    }

    public function test_a_save_leaves_no_temporary_file_behind(): void
    {
        $this->store->save(new OAuthTokens('access-1', 'refresh-1', new DateTimeImmutable('+300 days')));
        $this->store->save(new OAuthTokens('access-2', 'refresh-2', new DateTimeImmutable('+300 days')));

        $files = scandir(dirname($this->store->path()));

        self::assertSame(['.', '..', 'tracezilla-token.json'], $files);
        self::assertSame('access-2', $this->store->load()?->accessToken);
    }

    public function test_a_damaged_token_file_explains_how_to_recover(): void
    {
        mkdir(dirname($this->store->path()), 0700, true);
        file_put_contents($this->store->path(), 'not json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('logs in again with the credentials in .env');

        $this->store->load();
    }

    private function permissions(string $path): int
    {
        clearstatcache();

        return fileperms($path) & 0777;
    }
}
