<?php

declare(strict_types=1);

namespace Bifrost\Core\Tracezilla;

/**
 * Waits before a request is sent again. The real pause sleeps and says why;
 * tests use a pause that only records how long it was asked to wait.
 */
interface Pause
{
    /** @param string $reason Why the request is sent again, for the log */
    public function pause(int $seconds, string $reason): void;
}
