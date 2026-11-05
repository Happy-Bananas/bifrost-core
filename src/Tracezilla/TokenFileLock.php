<?php

declare(strict_types=1);

namespace Bifrost\Core\Tracezilla;

use RuntimeException;

/**
 * An exclusive lock on the token file. While one run holds it, other runs wait,
 * so two runs never use the same refresh token. Release it when done.
 */
final class TokenFileLock
{
    /** @param resource $handle */
    private function __construct(private $handle)
    {
    }

    public static function acquire(string $path): self
    {
        $handle = fopen($path, 'c');
        if ($handle === false) {
            throw new RuntimeException("Unable to open the lock file {$path}.");
        }
        chmod($path, 0600);
        if (! flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new RuntimeException("Unable to lock the lock file {$path}.");
        }

        return new self($handle);
    }

    public function release(): void
    {
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
    }
}
