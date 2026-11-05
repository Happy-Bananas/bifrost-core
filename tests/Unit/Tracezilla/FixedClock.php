<?php

declare(strict_types=1);

namespace Tests\Unit\Tracezilla;

use DateTimeImmutable;
use Bifrost\Core\Tracezilla\Clock;

/**
 * A clock for tests that always tells the same, chosen time.
 */
final class FixedClock implements Clock
{
    public function __construct(private DateTimeImmutable $time)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }
}
