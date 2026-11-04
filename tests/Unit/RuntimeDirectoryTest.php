<?php

declare(strict_types=1);

namespace Tests\Unit;

use Bifrost\Core\RuntimeDirectory;
use PHPUnit\Framework\TestCase;

final class RuntimeDirectoryTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('TRACEZILLA_RUNTIME_DIR');
    }

    public function test_it_defaults_to_var_in_the_project(): void
    {
        putenv('TRACEZILLA_RUNTIME_DIR');

        self::assertSame('/srv/project/var', RuntimeDirectory::resolve('/srv/project/'));
    }

    public function test_a_relative_path_starts_at_the_project(): void
    {
        putenv('TRACEZILLA_RUNTIME_DIR=runtime/');

        self::assertSame('/srv/project/runtime', RuntimeDirectory::resolve('/srv/project'));
    }

    public function test_an_absolute_path_is_used_as_it_is(): void
    {
        putenv('TRACEZILLA_RUNTIME_DIR=/var/lib/integration/');

        self::assertSame('/var/lib/integration', RuntimeDirectory::resolve('/srv/project'));
    }
}
