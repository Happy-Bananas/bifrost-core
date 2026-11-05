<?php

declare(strict_types=1);

namespace Tests\Unit\Seed;

use Bifrost\Core\Seed\SeedTeamGuard;
use PHPUnit\Framework\TestCase;

final class SeedTeamGuardTest extends TestCase
{
    public function test_a_dry_run_is_always_allowed(): void
    {
        self::assertNull(SeedTeamGuard::refusal(false, 'customer-production', ''));
    }

    public function test_it_writes_into_the_allowed_team(): void
    {
        self::assertNull(SeedTeamGuard::refusal(true, 'dev-team', 'dev-team'));
    }

    public function test_it_refuses_to_write_when_no_team_is_allowed(): void
    {
        $refusal = SeedTeamGuard::refusal(true, 'customer-production', '');

        self::assertNotNull($refusal);
        self::assertStringContainsString('TRACEZILLA_SEED_ALLOWED_TEAM=customer-production', $refusal);
        self::assertStringContainsString('Nothing was written.', $refusal);
    }

    public function test_it_refuses_to_write_into_another_team(): void
    {
        $refusal = SeedTeamGuard::refusal(true, 'customer-production', 'dev-team');

        self::assertNotNull($refusal);
        self::assertStringContainsString('[dev-team], but this project\'s team is [customer-production]', $refusal);
    }
}
