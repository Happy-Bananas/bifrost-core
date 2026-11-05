<?php

declare(strict_types=1);

namespace Tests\Unit\Tracezilla;

use Bifrost\Core\Tracezilla\Pause;

/**
 * A pause for tests: it does not wait, it only remembers each pause.
 */
final class RecordingPause implements Pause
{
    /** @var list<int> Seconds of each pause, in order */
    public array $seconds = [];

    /** @var list<string> */
    public array $reasons = [];

    public function pause(int $seconds, string $reason): void
    {
        $this->seconds[] = $seconds;
        $this->reasons[] = $reason;
    }
}
