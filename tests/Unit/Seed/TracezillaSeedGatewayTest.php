<?php

declare(strict_types=1);

namespace Tests\Unit\Seed;

use Bifrost\Core\Tracezilla\Client;
use Bifrost\Core\Tracezilla\OAuthTokens;
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
use Bifrost\Core\Seed\TracezillaSeedGateway;

final class TracezillaSeedGatewayTest extends TestCase
{
    private const SKU = [
        'sku_code' => 'TZ-DAIRY-002',
        'unit_of_measure' => 'pcs',
        'lot_unit' => 'colli',
        'default_uom_conversion' => 12,
        'weight_factor_net' => 1,
        'weight_factor_gross' => 1,
    ];

    private string $directory;
    private MockHandler $api;
    /** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/tz-gateway-'.bin2hex(random_bytes(4));
        $this->api = new MockHandler();
    }

    protected function tearDown(): void
    {
        $files = glob($this->directory.'/*');
        if ($files !== false) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
        @rmdir($this->directory);
    }

    public function test_an_sku_created_with_the_requested_units_is_left_alone(): void
    {
        $this->api->append($this->sku(['lot_unit' => 'colli', 'default_uom_conversion' => 12]));

        $this->gateway()->createSku(self::SKU);

        self::assertCount(1, $this->history);
    }

    public function test_units_replaced_on_create_are_corrected_with_the_lock_removed(): void
    {
        $this->api->append(
            $this->sku(['lot_unit' => 'pcs', 'default_uom_conversion' => 1]),
            $this->sku(['lot_unit' => 'colli', 'default_uom_conversion' => 12]),
        );

        $this->gateway()->createSku(self::SKU);

        $update = $this->history[1]['request'];
        self::assertSame('PUT', $update->getMethod());
        self::assertStringEndsWith('/skus/sku-1', $update->getUri()->getPath());
        $payload = json_decode((string) $update->getBody(), true);
        self::assertFalse($payload['lock_uom_conversion']);
        self::assertSame('colli', $payload['lot_unit']);
    }

    public function test_it_fails_when_the_correction_is_not_stored(): void
    {
        $this->api->append(
            $this->sku(['lot_unit' => 'pcs', 'default_uom_conversion' => 1]),
            $this->sku(['lot_unit' => 'pcs', 'default_uom_conversion' => 1]),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tracezilla stored units');

        $this->gateway()->createSku(self::SKU);
    }

    public function test_the_own_company_is_partner_number_one(): void
    {
        $this->api->append(new Response(200, [], json_encode(['data' => [
            ['id' => 'partner-2', 'number' => 2, 'name' => 'TZ Supplier', 'locations' => []],
            ['id' => 'partner-1', 'number' => 1, 'name' => 'Banana McShopface', 'locations' => [['id' => 'location-1', 'ref' => 'TZ-WAREHOUSE-MAIN']]],
        ]], JSON_THROW_ON_ERROR)));

        $ownCompany = $this->gateway()->ownCompany();

        self::assertSame('partner-1', $ownCompany['id']);
        self::assertSame('Banana McShopface', $ownCompany['name']);
        self::assertSame(['TZ-WAREHOUSE-MAIN' => 'location-1'], $ownCompany['locations']);
    }

    public function test_it_explains_a_company_without_partner_number_one(): void
    {
        $this->api->append(new Response(200, [], '{"data":[{"id":"partner-2","number":2,"name":"TZ Supplier"}]}'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no partner number 1');

        $this->gateway()->ownCompany();
    }

    private function sku(array $units): Response
    {
        return new Response(200, [], json_encode(['data' => ['id' => 'sku-1', 'unit_of_measure' => 'pcs'] + $units], JSON_THROW_ON_ERROR));
    }

    private function gateway(): TracezillaSeedGateway
    {
        $store = new TokenStore($this->directory.'/token.json');
        $store->save(new OAuthTokens('access', 'refresh', new DateTimeImmutable('+300 days')));
        $stack = HandlerStack::create($this->api);
        $stack->push(Middleware::history($this->history));

        return new TracezillaSeedGateway(new Client(
            new TracezillaConfiguration('https://tracezilla.test', 'team', 'id', 'secret', 'api-user', 'api-password', $store->path(), 5, 5),
            new TokenProvider(new HttpClient(['handler' => HandlerStack::create(new MockHandler())]), $store, 'id', 'secret', 'api-user', 'api-password'),
            new HttpClient(['handler' => $stack, 'base_uri' => 'https://tracezilla.test/api/v1/team/']),
        ));
    }
}
