<?php

declare(strict_types=1);

namespace Tests\Unit\Seed;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Bifrost\Core\Seed\SeedDefinition;
use Bifrost\Core\Seed\Seeder;
use Bifrost\Core\Seed\SeedResult;

final class SeederTest extends TestCase
{
    private InMemorySeedGateway $gateway;

    protected function setUp(): void
    {
        $this->gateway = new InMemorySeedGateway();
    }

    public function test_a_dry_run_plans_every_record_and_writes_nothing(): void
    {
        $result = $this->seeder()->run($this->definition(), execute: false);

        self::assertSame([], $this->gateway->writes);
        // The own company always exists; the other six records are planned.
        self::assertSame([SeedResult::EXISTS => 1, SeedResult::WOULD_CREATE => 6], $result->counts());
        self::assertTrue($result->isSuccessful());
    }

    public function test_execute_creates_records_in_dependency_order(): void
    {
        $result = $this->seeder()->run($this->definition(), execute: true);

        self::assertSame([
            'location TZ-WAREHOUSE-MAIN',
            'partner TZ-SUPPLIER',
            'location TZ-SUPPLIER-HQ',
            'sku TZ-A',
            'sku TZ-B',
            'purchase_order TZ-STOCK-001',
        ], $this->gateway->writes);
        self::assertSame([SeedResult::EXISTS => 1, SeedResult::CREATED => 6], $result->counts());
    }

    public function test_a_second_run_skips_everything(): void
    {
        $this->seeder()->run($this->definition(), execute: true);
        $this->gateway->writes = [];

        $result = $this->seeder()->run($this->definition(), execute: true);

        self::assertSame([], $this->gateway->writes);
        self::assertSame([SeedResult::EXISTS => 7], $result->counts());
    }

    public function test_it_builds_the_purchase_order_payload_from_resolved_ids(): void
    {
        $this->seeder()->run($this->definition(), execute: true);

        $payload = $this->gateway->purchaseOrders['TZ-STOCK-001'];
        $supplier = $this->gateway->partners['TZ-SUPPLIER'];
        $warehouse = $this->gateway->ownCompany;

        self::assertSame('2026-10-28', $payload['order_header']['delivery_date']);
        self::assertSame(
            ['partner_id' => $supplier['id'], 'location_id' => $supplier['locations']['TZ-SUPPLIER-HQ']],
            $payload['order_header']['partners']['supplier'],
        );
        self::assertSame(
            ['partner_id' => $warehouse['id'], 'location_id' => $warehouse['locations']['TZ-WAREHOUSE-MAIN']],
            $payload['order_header']['partners']['deliver_to'],
        );
        self::assertSame('receive', $payload['post_save_action']);
        self::assertSame([['sku_code' => 'TZ-A', 'quantity' => 10, 'unit_price' => 5]], $payload['inbound_lots']);
    }

    public function test_it_adds_a_missing_location_to_an_existing_partner(): void
    {
        $this->gateway->partners['TZ-SUPPLIER'] = ['id' => 'existing', 'locations' => []];

        $this->seeder()->run($this->definition(), execute: true);

        self::assertContains('location TZ-SUPPLIER-HQ', $this->gateway->writes);
        self::assertNotContains('partner TZ-SUPPLIER', $this->gateway->writes);
    }

    public function test_a_failed_sku_does_not_stop_the_other_records(): void
    {
        $this->gateway->failures['TZ-A'] = 'HTTP 422: lot_unit is invalid';

        $result = $this->seeder()->run($this->definition(), execute: true);

        self::assertContains('sku TZ-B', $this->gateway->writes);
        self::assertNotContains('purchase_order TZ-STOCK-001', $this->gateway->writes);
        self::assertFalse($result->isSuccessful());
        self::assertSame(['sku', 'TZ-A', SeedResult::FAILED, 'HTTP 422: lot_unit is invalid'], array_values($result->items()[4]));
    }

    public function test_a_failed_partner_fails_its_locations_and_orders(): void
    {
        $this->gateway->failures['TZ-SUPPLIER'] = 'HTTP 500';

        $result = $this->seeder()->run($this->definition(), execute: true);

        self::assertSame(SeedResult::FAILED, $result->items()[2]['action']);
        self::assertSame(SeedResult::FAILED, $result->items()[3]['action']);
        self::assertSame(SeedResult::INVALID, $result->items()[6]['action']);
        self::assertNotContains('purchase_order TZ-STOCK-001', $this->gateway->writes);
    }

    public function test_an_unknown_reference_is_invalid(): void
    {
        $data = $this->data();
        $data['purchase_orders'][0]['lines'][] = ['sku_code' => 'TZ-UNKNOWN', 'quantity' => 1];

        $result = $this->seeder()->run(SeedDefinition::fromArray($data), execute: false);

        $order = $result->items()[6];
        self::assertSame(SeedResult::INVALID, $order['action']);
        self::assertStringContainsString('TZ-UNKNOWN', $order['detail']);
    }

    public function test_new_partners_are_owned_by_the_seeding_user_unless_specified(): void
    {
        $data = $this->data();
        $data['partners'][] = ['ref' => 'TZ-OWNED', 'name' => 'Owned', 'country' => 'DK', 'owned_by_id' => 99];

        $this->seeder()->run(SeedDefinition::fromArray($data), execute: true);

        self::assertSame(2728, $this->gateway->partnerPayloads['TZ-SUPPLIER']['owned_by_id']);
        self::assertSame(99, $this->gateway->partnerPayloads['TZ-OWNED']['owned_by_id']);
    }

    public function test_the_own_company_is_named_in_the_result(): void
    {
        $result = $this->seeder()->run($this->definition(), execute: false);

        self::assertContains(
            ['type' => 'partner', 'key' => 'own_company', 'action' => 'exists', 'detail' => 'Own Company (partner number 1)'],
            $result->items(),
        );
    }

    public function test_an_existing_own_company_location_is_reused(): void
    {
        $this->gateway->ownCompany['locations']['TZ-WAREHOUSE-MAIN'] = 'existing-warehouse';

        $this->seeder()->run($this->definition(), execute: true);

        self::assertNotContains('location TZ-WAREHOUSE-MAIN', $this->gateway->writes);
        self::assertSame(
            'existing-warehouse',
            $this->gateway->purchaseOrders['TZ-STOCK-001']['order_header']['partners']['deliver_to']['location_id'],
        );
    }

    private function seeder(): Seeder
    {
        return new Seeder($this->gateway, 2728, new DateTimeImmutable('2026-10-28'));
    }

    private function definition(): SeedDefinition
    {
        return SeedDefinition::fromArray($this->data());
    }

    private function data(): array
    {
        return [
            'version' => 1,
            'own_company' => ['locations' => [$this->location('TZ-WAREHOUSE-MAIN')]],
            'partners' => [
                ['ref' => 'TZ-SUPPLIER', 'name' => 'Supplier', 'country' => 'DK', 'locations' => [$this->location('TZ-SUPPLIER-HQ')]],
            ],
            'skus' => [$this->sku('TZ-A'), $this->sku('TZ-B')],
            'purchase_orders' => [[
                'ext_ref' => 'TZ-STOCK-001',
                'supplier' => ['partner' => 'TZ-SUPPLIER', 'location' => 'TZ-SUPPLIER-HQ'],
                'deliver_to' => ['partner' => 'own_company', 'location' => 'TZ-WAREHOUSE-MAIN'],
                'currency' => 'DKK',
                'exchange_rate' => 100,
                'receive' => true,
                'lines' => [['sku_code' => 'TZ-A', 'quantity' => 10, 'unit_price' => 5]],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function location(string $ref): array
    {
        return ['ref' => $ref, 'name' => $ref, 'country' => 'DK'];
    }

    /** @return array<string, mixed> */
    private function sku(string $code): array
    {
        return [
            'sku_code' => $code,
            'unit_of_measure' => 'pcs',
            'lot_unit' => 'pcs',
            'weight_factor_net' => 1,
            'weight_factor_gross' => 1,
        ];
    }
}
