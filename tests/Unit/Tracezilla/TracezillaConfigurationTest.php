<?php

declare(strict_types=1);

namespace Tests\Unit\Tracezilla;

use Bifrost\Core\Tracezilla\TracezillaConfiguration;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TracezillaConfigurationTest extends TestCase
{
    private const VARIABLES = [
        'TRACEZILLA_BASE_URL',
        'TRACEZILLA_TEAM_SLUG',
        'TRACEZILLA_CLIENT_ID',
        'TRACEZILLA_CLIENT_SECRET',
        'TRACEZILLA_TOKEN_FILE',
        'TRACEZILLA_RUNTIME_DIR',
        'HTTP_TIMEOUT',
        'HTTP_CONNECT_TIMEOUT',
    ];

    protected function setUp(): void
    {
        putenv('TRACEZILLA_BASE_URL=https://app.tracezilla.test/');
        putenv('TRACEZILLA_TEAM_SLUG=dev-team');
        putenv('TRACEZILLA_CLIENT_ID=client-id');
        putenv('TRACEZILLA_CLIENT_SECRET=client-secret');
    }

    protected function tearDown(): void
    {
        foreach (self::VARIABLES as $variable) {
            putenv($variable);
        }
    }

    public function test_it_reads_the_settings(): void
    {
        $configuration = TracezillaConfiguration::fromEnvironment('/srv/project');

        self::assertSame('https://app.tracezilla.test', $configuration->baseUrl);
        self::assertSame('dev-team', $configuration->teamSlug);
        self::assertSame('client-id', $configuration->clientId);
        self::assertSame('client-secret', $configuration->clientSecret);
        self::assertSame(30.0, $configuration->timeout);
        self::assertSame(10.0, $configuration->connectTimeout);
    }

    public function test_the_token_is_kept_in_the_runtime_directory(): void
    {
        self::assertSame('/srv/project/var/tracezilla-token.json', TracezillaConfiguration::fromEnvironment('/srv/project')->tokenFile);

        putenv('TRACEZILLA_RUNTIME_DIR=/var/lib/integration');

        self::assertSame('/var/lib/integration/tracezilla-token.json', TracezillaConfiguration::fromEnvironment('/srv/project')->tokenFile);
    }

    public function test_a_token_file_can_be_named(): void
    {
        putenv('TRACEZILLA_TOKEN_FILE=secrets/token.json');

        self::assertSame('/srv/project/secrets/token.json', TracezillaConfiguration::fromEnvironment('/srv/project')->tokenFile);

        putenv('TRACEZILLA_TOKEN_FILE=/etc/integration/token.json');

        self::assertSame('/etc/integration/token.json', TracezillaConfiguration::fromEnvironment('/srv/project')->tokenFile);
    }

    public function test_a_missing_setting_is_named(): void
    {
        putenv('TRACEZILLA_TEAM_SLUG');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing required environment variable: TRACEZILLA_TEAM_SLUG');

        TracezillaConfiguration::fromEnvironment('/srv/project');
    }
}
