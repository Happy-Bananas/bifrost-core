<?php

declare(strict_types=1);

namespace Bifrost\Core\Lock;

/**
 * A lock this run holds. Release it when done; it is also released when the
 * run ends.
 */
final class AcquiredIntegrationLock
{
    private bool $released = false;

    /** @param resource $handle */
    public function __construct(private $handle) {}

    public function release(): void
    {
        if ($this->released) {
            return;
        }
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->released = true;
    }

    public function __destruct()
    {
        $this->release();
    }
}

