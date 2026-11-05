<?php

declare(strict_types=1);

namespace Bifrost\Core\Seed;

use Bifrost\Core\Lock\RunLock;
use Bifrost\Core\Tracezilla\Client;
use Bifrost\Core\Tracezilla\TokenProvider;
use Bifrost\Core\Tracezilla\TracezillaConfiguration;
use Bifrost\Core\WriteMode;
use InvalidArgumentException;
use Throwable;

/**
 * The tracezilla:seed command: fills a development company with the test
 * dataset. An integration's bin/seed-tracezilla only loads .env and runs this.
 */
final class SeedCommand
{
    private const USAGE = <<<'TEXT'
        Usage: php bin/bifrost-connect tracezilla:seed [<seed-file>] (--dry-run | --execute)

        Creates the partners, locations, SKUs and purchase orders described in the
        seed file in the tracezilla team from .env. Existing records are skipped.
        Locations under own_company are added to your own company: partner
        number 1, which tracezilla creates together with the company.

          --dry-run              Show what would be created. Nothing is written.
          --execute              Create the missing records.

        Default seed file: seeds/tracezilla.yaml in the project, or else the
        standard test dataset that comes with bifrost-core.

        TEXT;

    /** The standard test dataset, used when the project has no seed file of its own. */
    private const STANDARD_SEED_FILE = __DIR__.'/../../seeds/tracezilla.yaml';

    private bool $dryRun = false;
    private bool $execute = false;
    /** @var list<string> */
    private array $files = [];

    public function __construct(private string $projectDirectory)
    {
    }

    /**
     * @param list<string> $arguments The command-line arguments after the command name
     * @return int The exit code: 0 done, 1 failed, 2 wrong options or refused
     */
    public function run(array $arguments): int
    {
        if (in_array('--help', $arguments, true) || in_array('-h', $arguments, true)) {
            echo self::USAGE;

            return 0;
        }
        if (! $this->readArguments($arguments)) {
            fwrite(STDERR, self::USAGE);

            return 2;
        }
        $mode = $this->writeMode();
        if ($mode === null) {
            return 2;
        }
        RunLock::takeOrExit($this->projectDirectory, 'tracezilla:seed');

        return $this->seed($mode->writes());
    }

    /**
     * Remember the options and the seed file; false if an argument is unknown.
     *
     * @param list<string> $arguments
     */
    private function readArguments(array $arguments): bool
    {
        foreach ($arguments as $argument) {
            if ($argument === '--dry-run') {
                $this->dryRun = true;
            } elseif ($argument === '--execute') {
                $this->execute = true;
            } elseif (str_starts_with($argument, '--')) {
                return false;
            } else {
                $this->files[] = $argument;
            }
        }

        return count($this->files) <= 1;
    }

    /** The chosen mode, or null after explaining that none or both were chosen. */
    private function writeMode(): ?WriteMode
    {
        try {
            $mode = WriteMode::fromOptions($this->dryRun, $this->execute);
        } catch (InvalidArgumentException $exception) {
            fwrite(STDERR, $exception->getMessage()."\n");

            return null;
        }
        if (! $mode->isChosen()) {
            fwrite(STDERR, WriteMode::CHOOSE_A_MODE."\n\n".self::USAGE);

            return null;
        }

        return $mode;
    }

    private function seed(bool $execute): int
    {
        try {
            $definition = SeedDefinition::fromFile($this->seedFile());
            $configuration = TracezillaConfiguration::fromEnvironment($this->projectDirectory);
        } catch (Throwable $exception) {
            fwrite(STDERR, 'Seed failed: '.$exception->getMessage()."\n");

            return 1;
        }

        $this->printHeading($execute, $configuration->teamSlug);
        try {
            $tokens = TokenProvider::fromConfiguration($configuration);
            $client = new Client($configuration, $tokens);
            $seeder = new Seeder(new TracezillaSeedGateway($client), $tokens->userId());
            $result = $seeder->run($definition, $execute);
        } catch (Throwable $exception) {
            fwrite(STDERR, 'Seed failed: '.$exception->getMessage()."\n");

            return 1;
        }

        $this->printResult($result, $execute);
        if ($result->isSuccessful()) {
            return 0;
        }

        return 1;
    }

    /** The file named on the command line, the project's own, or the standard dataset. */
    private function seedFile(): string
    {
        if (count($this->files) === 1) {
            return $this->files[0];
        }
        $projectSeedFile = rtrim($this->projectDirectory, '/').'/seeds/tracezilla.yaml';
        if (is_file($projectSeedFile)) {
            return $projectSeedFile;
        }

        return self::STANDARD_SEED_FILE;
    }

    private function printHeading(bool $execute, string $teamSlug): void
    {
        if ($execute) {
            echo "Seeding tracezilla team [{$teamSlug}]\n\n";
        } else {
            echo "Dry run for tracezilla team [{$teamSlug}]\n\n";
        }
    }

    private function printResult(SeedResult $result, bool $execute): void
    {
        foreach ($result->items() as $item) {
            printf("%-15s %-14s %-35s %s\n", $item['type'], $item['action'], $item['key'], $item['detail']);
        }

        echo "\n";
        $counts = $result->counts();
        foreach ($counts as $action => $count) {
            echo "{$action}: {$count}\n";
        }

        $wouldCreateSomething = isset($counts[SeedResult::WOULD_CREATE]);
        if (! $execute && $wouldCreateSomething) {
            echo "\nDry run only. Re-run with --execute instead of --dry-run to write.\n";
        }
    }
}
