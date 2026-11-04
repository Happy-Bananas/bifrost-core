<?php

declare(strict_types=1);

namespace Tests\Unit\Lock;

use Bifrost\Core\Lock\FileIntegrationLock;
use FilesystemIterator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class FileIntegrationLockTest extends TestCase
{
    private string $runtimeDirectory;

    protected function setUp(): void
    {
        $this->runtimeDirectory = sys_get_temp_dir().'/bifrost-lock-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (! is_dir($this->runtimeDirectory)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->runtimeDirectory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $this->remove($item);
        }
        rmdir($this->runtimeDirectory);
    }

    public function test_only_one_run_can_hold_the_lock(): void
    {
        $locks = new FileIntegrationLock($this->runtimeDirectory);
        $first = $locks->acquire('integration', ['command' => 'tracezilla:seed']);

        self::assertNotNull($first);
        self::assertNull((new FileIntegrationLock($this->runtimeDirectory))->acquire(
            'integration',
            ['command' => 'orders:import'],
        ));
        $metadata = $locks->metadata('integration');
        self::assertSame('tracezilla:seed', $metadata['command']);

        $first->release();
        $second = $locks->acquire('integration', ['command' => 'orders:import']);
        self::assertNotNull($second);
        $second->release();
    }

    public function test_the_lock_is_released_when_the_run_forgets_it(): void
    {
        $locks = new FileIntegrationLock($this->runtimeDirectory);
        $first = $locks->acquire('integration');
        self::assertNotNull($first);

        // Dropping the last reference releases the lock, as the end of a run does.
        $first = null;

        $second = $locks->acquire('integration');
        self::assertNotNull($second);
        $second->release();
    }

    public function test_the_lock_files_are_private(): void
    {
        $locks = new FileIntegrationLock($this->runtimeDirectory);
        $lock = $locks->acquire('integration');
        self::assertNotNull($lock);

        self::assertSame('0700', $this->permissions($this->runtimeDirectory.'/locks'));
        self::assertSame('0600', $this->permissions($this->runtimeDirectory.'/locks/integration.lock'));
        $lock->release();
    }

    public function test_it_refuses_a_lock_name_that_could_leave_the_lock_directory(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new FileIntegrationLock($this->runtimeDirectory))->acquire('../integration');
    }

    public function test_without_a_lock_file_there_is_no_metadata(): void
    {
        $locks = new FileIntegrationLock($this->runtimeDirectory);

        self::assertSame([], $locks->metadata('integration'));
    }

    private function remove(SplFileInfo $item): void
    {
        if ($item->isDir()) {
            rmdir($item->getPathname());

            return;
        }
        unlink($item->getPathname());
    }

    private function permissions(string $path): string
    {
        clearstatcache();

        return substr(sprintf('%o', fileperms($path)), -4);
    }
}
