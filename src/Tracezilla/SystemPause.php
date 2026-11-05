<?php

declare(strict_types=1);

namespace Bifrost\Core\Tracezilla;

/**
 * The real pause: writes the reason to the error output, which ends up in the
 * cron log, and then sleeps.
 */
final class SystemPause implements Pause
{
    public function pause(int $seconds, string $reason): void
    {
        fwrite(STDERR, "{$reason}. Trying again in {$seconds} seconds.\n");
        sleep($seconds);
    }
}
