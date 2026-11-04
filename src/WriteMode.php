<?php

declare(strict_types=1);

namespace Bifrost\Core;

use InvalidArgumentException;

/**
 * Whether a command that can change data only previews (--dry-run) or writes
 * (--execute). The developer has to choose one explicitly.
 */
final readonly class WriteMode
{
    public const CHOOSE_A_MODE = 'Choose a mode: --dry-run to preview, or --execute to write.';

    private function __construct(private string $mode)
    {
    }

    /** Build the mode from the command's options; contradictory options are refused. */
    public static function fromOptions(bool $dryRun, bool $execute): self
    {
        if ($dryRun && $execute) {
            throw new InvalidArgumentException('Use either --dry-run or --execute, not both.');
        }

        if ($execute) {
            return new self('execute');
        }
        if ($dryRun) {
            return new self('dry_run');
        }

        return new self('not_chosen');
    }

    public static function dryRun(): self
    {
        return new self('dry_run');
    }

    public static function execute(): self
    {
        return new self('execute');
    }

    public static function notChosen(): self
    {
        return new self('not_chosen');
    }

    public function isChosen(): bool
    {
        return $this->mode !== 'not_chosen';
    }

    public function writes(): bool
    {
        return $this->mode === 'execute';
    }
}
