<?php

declare(strict_types=1);

namespace Tests\Unit\Seed;

use Bifrost\Core\Lock\FileIntegrationLock;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Tracezilla\TemporaryDirectory;

/**
 * Runs the seed command in a separate PHP process, as bin/seed-tracezilla
 * does, with its own settings and no stored token. No test reaches tracezilla.
 */
final class SeedCommandTest extends TestCase
{
    private TemporaryDirectory $project;

    protected function setUp(): void
    {
        $this->project = new TemporaryDirectory();
        mkdir($this->project->path);
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function test_execute_is_refused_for_a_team_that_is_not_allowed(): void
    {
        [$exitCode, $output] = $this->seed(['--execute'], ['TRACEZILLA_SEED_ALLOWED_TEAM' => 'dev-team']);

        self::assertSame(2, $exitCode);
        self::assertStringContainsString('but this project\'s team is [customer-production]', $output);
        self::assertStringNotContainsString('Seeding tracezilla team', $output);
    }

    public function test_execute_is_refused_when_no_team_is_allowed(): void
    {
        [$exitCode, $output] = $this->seed(['--execute'], []);

        self::assertSame(2, $exitCode);
        self::assertStringContainsString('TRACEZILLA_SEED_ALLOWED_TEAM=customer-production', $output);
    }

    public function test_a_dry_run_needs_no_allowed_team(): void
    {
        [$exitCode, $output] = $this->seed(['--dry-run'], []);

        // It gets past the guard and stops only because nobody has logged in.
        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Dry run for tracezilla team [customer-production]', $output);
        self::assertStringContainsString('No tracezilla token is stored', $output);
    }

    public function test_the_project_s_own_seed_file_comes_first(): void
    {
        mkdir($this->project->path.'/seeds');
        file_put_contents($this->project->path.'/seeds/tracezilla.yaml', "version: 99\n");

        [$exitCode, $output] = $this->seed(['--dry-run'], []);

        // The standard dataset is valid; this one is not, so it was the one read.
        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Invalid seed definition', $output);
    }

    public function test_it_is_skipped_while_another_command_holds_the_run_lock(): void
    {
        $other = (new FileIntegrationLock($this->project->path.'/var'))->acquire('integration', ['command' => 'scenario:run']);
        self::assertNotNull($other);

        [$exitCode, $output] = $this->seed(['--dry-run'], []);
        $other->release();

        self::assertSame(75, $exitCode);
        self::assertStringContainsString('Command: scenario:run', $output);
        self::assertStringNotContainsString('Dry run for tracezilla team', $output);
    }

    public function test_it_needs_a_mode(): void
    {
        [$exitCode, $output] = $this->seed([], []);

        self::assertSame(2, $exitCode);
        self::assertStringContainsString('Choose a mode', $output);
    }

    public function test_it_refuses_an_unknown_option(): void
    {
        [$exitCode, $output] = $this->seed(['--execute', '--confirm'], []);

        self::assertSame(2, $exitCode);
        self::assertStringContainsString('Usage:', $output);
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $settings Extra environment variables
     * @return array{int, string} The exit code and everything the command printed
     */
    private function seed(array $arguments, array $settings): array
    {
        $environment = [
            'PATH' => (string) getenv('PATH'),
            'TRACEZILLA_BASE_URL' => 'https://tracezilla.test',
            'TRACEZILLA_TEAM_SLUG' => 'customer-production',
            'TRACEZILLA_CLIENT_ID' => 'client-id',
            'TRACEZILLA_CLIENT_SECRET' => 'client-secret',
        ];
        foreach ($settings as $name => $value) {
            $environment[$name] = $value;
        }

        $autoload = dirname(__DIR__, 3).'/vendor/autoload.php';
        $code = 'require '.var_export($autoload, true).';'
            .' exit((new Bifrost\Core\Seed\SeedCommand('.var_export($this->project->path, true).'))->run('.var_export($arguments, true).'));';
        $command = [PHP_BINARY, '-r', $code];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, $environment);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exitCode = proc_close($process);

        return [$exitCode, $output];
    }
}
