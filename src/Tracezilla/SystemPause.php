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
        $unit = 'seconds';
        if ($seconds === 1) {
            $unit = 'second';
        }
        fwrite(STDERR, "{$reason}. Trying again in {$seconds} {$unit}.\n");
        sleep($seconds);
    }
}
