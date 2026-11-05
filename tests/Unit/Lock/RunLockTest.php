<?php

declare(strict_types=1);

namespace Tests\Unit\Lock;

use Bifrost\Core\Lock\FileIntegrationLock;
use Bifrost\Core\Lock\RunLock;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Tracezilla\TemporaryDirectory;

/**
 * Runs each command in a separate PHP process, as a real integration command
 * runs, because a skipped run ends the process.
 */
final class RunLockTest extends TestCase
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

    public function test_a_free_lock_is_taken_and_the_command_continues(): void
    {
        [$exitCode, $output] = $this->command('scenario:run', 'echo "working\n";');

        self::assertSame(0, $exitCode);
        self::assertSame("working\n", $output);
        $metadata = (new FileIntegrationLock($this->project->path.'/var'))->metadata('integration');
        self::assertSame('scenario:run', $metadata['command']);
    }

    public function test_a_command_is_skipped_while_another_holds_the_lock(): void
    {
        $other = (new FileIntegrationLock($this->project->path.'/var'))->acquire('integration', ['command' => 'orders:import-individual']);
        self::assertNotNull($other);

        [$exitCode, $output] = $this->command('scenario:run', 'echo "working\n";');
        $other->release();

        self::assertSame(RunLock::SKIPPED_EXIT_CODE, $exitCode);
        self::assertStringNotContainsString('working', $output);
        self::assertStringContainsString('Another integration command is already running.', $output);
        self::assertStringContainsString('Command: orders:import-individual', $output);
    }

    public function test_the_lock_is_held_until_the_command_ends(): void
    {
        // The command takes the lock without keeping a variable, then asks a
        // second lock object whether the lock is still taken.
        $check = '$locks = new Bifrost\Core\Lock\FileIntegrationLock('.var_export($this->project->path.'/var', true).');'
            .' if ($locks->acquire("integration") === null) { echo "held\n"; } else { echo "free\n"; }';

        [$exitCode, $output] = $this->command('scenario:run', $check);

        self::assertSame(0, $exitCode);
        self::assertSame("held\n", $output);
    }

    public function test_the_lock_is_free_again_after_the_command(): void
    {
        $this->command('scenario:run', '');

        $lock = (new FileIntegrationLock($this->project->path.'/var'))->acquire('integration');

        self::assertNotNull($lock);
        $lock->release();
    }

    /**
     * Run PHP code after RunLock::takeOrExit in a separate process.
     *
     * @return array{int, string} The exit code and everything it printed
     */
    private function command(string $commandName, string $code): array
    {
        $autoload = dirname(__DIR__, 3).'/vendor/autoload.php';
        $script = 'require '.var_export($autoload, true).';'
            .' Bifrost\Core\Lock\RunLock::takeOrExit('.var_export($this->project->path, true).', '.var_export($commandName, true).');'
            .' '.$code;
        $environment = ['PATH' => (string) getenv('PATH')];
        $process = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, $environment);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), $output];
    }
}
