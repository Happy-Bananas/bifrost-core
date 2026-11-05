<?php

declare(strict_types=1);

namespace Bifrost\Core\Seed;

/**
 * Keeps the seed out of a customer's production team. The seed writes test
 * partners, SKUs and stock, so it writes only into the team that
 * TRACEZILLA_SEED_ALLOWED_TEAM in .env names. A dry run only reads and is
 * always allowed.
 */
final class SeedTeamGuard
{
    public const ALLOWED_TEAM_VARIABLE = 'TRACEZILLA_SEED_ALLOWED_TEAM';

    /**
     * Why the seed may not write into this team, or null if it may.
     *
     * @param string $allowedTeam The value of TRACEZILLA_SEED_ALLOWED_TEAM; '' if not set
     */
    public static function refusal(bool $execute, string $teamSlug, string $allowedTeam): ?string
    {
        if (! $execute) {
            return null;
        }
        if ($allowedTeam === $teamSlug) {
            return null;
        }

        $variable = self::ALLOWED_TEAM_VARIABLE;
        if ($allowedTeam === '') {
            return "The seed writes test data, so it writes only into the team named by {$variable} in .env, "
                ."and that is not set. If team [{$teamSlug}] is a development company, add "
                ."{$variable}={$teamSlug} to .env. Never set it for a customer's production team. Nothing was written.";
        }

        return "{$variable} is [{$allowedTeam}], but this project's team is [{$teamSlug}]. "
            .'The seed writes only into the allowed team. Nothing was written.';
    }
}
