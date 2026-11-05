<?php

declare(strict_types=1);

namespace Bifrost\Core\Lock;

use Bifrost\Core\RuntimeDirectory;

/**
 * The integration's run lock, taken by every command that reads from or writes
 * to the services in a scheduled run. Only one such command runs at a time, so
 * runs never overlap and never write the same record twice. A command that
 * finds the lock taken stops with exit code 75: skipped, not failed.
 *
 * Each command takes the lock itself, so it is protected whether it is started
 * through bin/bifrost-connect or directly.
 */
final class RunLock
{
    /** The exit code of a run that was skipped because another run holds the lock. */
    public const SKIPPED_EXIT_CODE = 75;

    private const NAME = 'integration';

    /** The lock this process holds until it ends. */
    private static ?AcquiredIntegrationLock $held = null;

    /**
     * Take the run lock for the rest of this process, or explain that another
     * command holds it and stop with SKIPPED_EXIT_CODE.
     */
    public static function takeOrExit(string $projectDirectory, string $command): void
    {
        if (self::$held !== null) {
            return;
        }

        $locks = new FileIntegrationLock(RuntimeDirectory::resolve($projectDirectory));
        $lock = $locks->acquire(self::NAME, ['command' => $command]);
        if ($lock === null) {
            self::reportSkippedRun($locks->metadata(self::NAME));
            exit(self::SKIPPED_EXIT_CODE);
        }

        // Kept in a static property, so the lock is released only when the
        // process ends, not when the caller's variable goes out of scope.
        self::$held = $lock;
    }

    /**
     * Explain that another command holds the lock, using what it wrote into the
     * lock file.
     *
     * @param array<string, mixed> $metadata
     */
    private static function reportSkippedRun(array $metadata): void
    {
        fwrite(STDERR, "Another integration command is already running.\n");
        if (isset($metadata['command']) && is_string($metadata['command'])) {
            fwrite(STDERR, "Command: {$metadata['command']}\n");
        }
        if (isset($metadata['acquired_at']) && is_string($metadata['acquired_at'])) {
            fwrite(STDERR, "Started: {$metadata['acquired_at']}\n");
        }
        fwrite(STDERR, "This run was skipped safely; the next scheduled run can retry.\n");
    }
}
