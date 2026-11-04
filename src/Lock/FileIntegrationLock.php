<?php

declare(strict_types=1);

namespace Bifrost\Core\Lock;

use InvalidArgumentException;
use RuntimeException;

/**
 * Named locks in the runtime directory. Only one run can hold a lock at a
 * time, so scheduled runs never overlap. The lock file also records which
 * command holds the lock and since when.
 */
final class FileIntegrationLock
{
    private string $lockDirectory;

    public function __construct(string $runtimeDirectory)
    {
        $this->lockDirectory = $runtimeDirectory.'/locks';
        $this->createLockDirectory();
        chmod($this->lockDirectory, 0700);
    }

    /**
     * Take the lock, or return null if another run holds it. $metadata is
     * written into the lock file, for example the command name.
     *
     * @param array<string, mixed> $metadata
     */
    public function acquire(string $name, array $metadata = []): ?AcquiredIntegrationLock
    {
        $path = $this->path($name);
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new RuntimeException("Could not open integration lock [{$path}].");
        }
        chmod($path, 0600);

        // LOCK_NB: do not wait; if another run holds the lock, give up at once.
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        $this->writeMetadata($handle, $path, $metadata);

        return new AcquiredIntegrationLock($handle);
    }

    /**
     * What the current or last holder wrote into the lock file.
     *
     * @return array<string, mixed>
     */
    public function metadata(string $name): array
    {
        $path = $this->path($name);
        if (! is_file($path)) {
            return [];
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            return [];
        }
        $data = json_decode($contents, true);
        if (! is_array($data)) {
            return [];
        }

        return $data;
    }

    /**
     * @param resource $handle
     * @param array<string, mixed> $metadata
     */
    private function writeMetadata($handle, string $path, array $metadata): void
    {
        $payload = $metadata;
        $payload['pid'] = getmypid();
        $payload['acquired_at'] = gmdate(DATE_ATOM);
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

        ftruncate($handle, 0);
        rewind($handle);
        $written = fwrite($handle, $json);
        $flushed = fflush($handle);
        if ($written === strlen($json) && $flushed) {
            return;
        }

        flock($handle, LOCK_UN);
        fclose($handle);
        throw new RuntimeException("Could not write integration-lock metadata [{$path}].");
    }

    private function createLockDirectory(): void
    {
        if (is_dir($this->lockDirectory)) {
            return;
        }
        if (mkdir($this->lockDirectory, 0700, true)) {
            return;
        }
        // Another run may have created it at the same moment.
        if (is_dir($this->lockDirectory)) {
            return;
        }
        throw new RuntimeException("Could not create lock directory [{$this->lockDirectory}].");
    }

    private function path(string $name): string
    {
        if (! preg_match('/^[a-z0-9][a-z0-9._-]*$/', $name)) {
            throw new InvalidArgumentException('Lock name contains unsafe characters.');
        }

        return $this->lockDirectory.'/'.$name.'.lock';
    }
}
