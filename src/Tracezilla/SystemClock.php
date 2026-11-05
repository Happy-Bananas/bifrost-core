<?php

declare(strict_types=1);

namespace Bifrost\Core\Tracezilla;

use DateTimeImmutable;

/**
 * The real clock: the computer's current time.
 */
final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
