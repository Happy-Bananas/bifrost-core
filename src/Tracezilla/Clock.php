<?php

declare(strict_types=1);

namespace Bifrost\Core\Tracezilla;

use DateTimeImmutable;

/**
 * Tells the current time. The real clock reads the computer's time; tests use
 * a clock that is fixed at a chosen moment.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
