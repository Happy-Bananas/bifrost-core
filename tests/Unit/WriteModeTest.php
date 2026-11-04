<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Bifrost\Core\WriteMode;

final class WriteModeTest extends TestCase
{
    public function test_dry_run_previews(): void
    {
        $mode = WriteMode::fromOptions(dryRun: true, execute: false);

        self::assertTrue($mode->isChosen());
        self::assertFalse($mode->writes());
    }

    public function test_execute_writes(): void
    {
        $mode = WriteMode::fromOptions(dryRun: false, execute: true);

        self::assertTrue($mode->writes());
    }

    public function test_no_option_means_no_mode_was_chosen(): void
    {
        $mode = WriteMode::fromOptions(dryRun: false, execute: false);

        self::assertFalse($mode->isChosen());
        self::assertFalse($mode->writes());
    }

    public function test_dry_run_with_execute_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Use either --dry-run or --execute, not both.');

        WriteMode::fromOptions(dryRun: true, execute: true);
    }
}
