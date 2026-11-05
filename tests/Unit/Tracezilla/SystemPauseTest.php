<?php

declare(strict_types=1);

namespace Tests\Unit\Tracezilla;

use Bifrost\Core\Tracezilla\SystemPause;
use PHPUnit\Framework\TestCase;

final class SystemPauseTest extends TestCase
{
    public function test_it_says_why_and_how_long_it_waits(): void
    {
        $output = $this->errorOutputOf(0, 'GET /skus: tracezilla answered HTTP 503');

        self::assertSame("GET /skus: tracezilla answered HTTP 503. Trying again in 0 seconds.\n", $output);
    }

    /** Run the pause in a separate PHP process and return what it wrote to STDERR. */
    private function errorOutputOf(int $seconds, string $reason): string
    {
        $autoload = dirname(__DIR__, 3).'/vendor/autoload.php';
        $code = 'require '.var_export($autoload, true).'; (new Bifrost\Core\Tracezilla\SystemPause())->pause('.$seconds.', '.var_export($reason, true).');';
        $command = escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($code).' 2>&1';

        return (string) shell_exec($command);
    }
}
