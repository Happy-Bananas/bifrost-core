<?php

declare(strict_types=1);

namespace Tests\Unit\Tracezilla;

use Bifrost\Core\Tracezilla\Client;
use Bifrost\Core\Tracezilla\OAuthTokens;
use Bifrost\Core\Tracezilla\SkuCreator;
use Bifrost\Core\Tracezilla\TokenProvider;
use Bifrost\Core\Tracezilla\TokenStore;
use Bifrost\Core\Tracezilla\TracezillaConfiguration;
use DateTimeImmutable;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SkuCreatorTest extends TestCase
{
    private const SKU = [
        'sku_code' => 'TZ-DAIRY-002',
        'unit_of_measure' => 'pcs',
        'lot_unit' => 'colli',
        'default_uom_conversion' => 12,
        'weight_factor_net' => 1,
        'weight_factor_gross' => 1,
    ];

    private TemporaryDirectory $directory;
    private MockHandler $api;
    /** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    protected function setUp(): void
    {
        $this->directory = new TemporaryDirectory();
        $this->api = new MockHandler();
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    public function test_an_sku_created_with_the_requested_units_is_left_alone(): void
    {
        $this->api->append($this->sku('colli', 12));

        $this->creator()->create(self::SKU);

        self::assertCount(1, $this->history);
    }

    public function test_units_replaced_on_create_are_corrected_with_the_lock_removed(): void
    {
        $this->api->append($this->sku('pcs', 1), $this->sku('colli', 12));

        $this->creator()->create(self::SKU);

        $update = $this->history[1]['request'];
        self::assertSame('PUT', $update->getMethod());
        self::assertStringEndsWith('/skus/sku-1', $update->getUri()->getPath());
        $payload = json_decode((string) $update->getBody(), true);
        self::assertFalse($payload['lock_uom_conversion']);
        self::assertSame('colli', $payload['lot_unit']);
    }

    public function test_it_fails_when_the_correction_is_not_stored(): void
    {
        $this->api->append($this->sku('pcs', 1), $this->sku('pcs', 1));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tracezilla stored units');

        $this->creator()->create(self::SKU);
    }

    /** The answer tracezilla gives for an SKU with these units. */
    private function sku(string $lotUnit, int $conversion): Response
    {
        $data = [
            'id' => 'sku-1',
            'unit_of_measure' => 'pcs',
            'lot_unit' => $lotUnit,
            'default_uom_conversion' => $conversion,
        ];

        return new Response(200, [], json_encode(['data' => $data], JSON_THROW_ON_ERROR));
    }

    private function creator(): SkuCreator
    {
        $store = new TokenStore($this->directory->path.'/token.json');
        $store->save(new OAuthTokens('access', 'refresh', new DateTimeImmutable('+300 days')));
        $stack = HandlerStack::create($this->api);
        $stack->push(Middleware::history($this->history));

        return new SkuCreator(new Client(
            new TracezillaConfiguration('https://tracezilla.test', 'team', 'id', 'secret', 'api-user', 'api-password', $store->path(), 5, 5),
            new TokenProvider(new HttpClient(['handler' => HandlerStack::create(new MockHandler())]), $store, 'id', 'secret', 'api-user', 'api-password'),
            new HttpClient(['handler' => $stack, 'base_uri' => 'https://tracezilla.test/api/v1/team/']),
        ));
    }
}
