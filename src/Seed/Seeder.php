<?php

declare(strict_types=1);

namespace Bifrost\Core\Seed;

use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * Creates the records of a seed definition that do not exist in tracezilla.
 *
 * Existing records are reported and left untouched. In a dry run nothing is
 * written, and the records that would be created are reported as would_create.
 */
final class Seeder
{
    /**
     * The partners known so far: those in tracezilla, plus those this run
     * created or would create. The ID is null for a partner or location that
     * exists only in a dry run.
     *
     * @var array<string, array{id: ?string, locations: array<string, ?string>}>
     */
    private array $partners = [];

    /** @var array<string, true> The SKU codes known so far */
    private array $skuCodes = [];

    private SeedResult $result;
    private bool $dryRun = true;

    public function __construct(
        private SeedGateway $gateway,
        private ?int $ownerId = null,
        private DateTimeImmutable $today = new DateTimeImmutable('today'),
    ) {
        $this->result = new SeedResult();
    }

    public function run(SeedDefinition $definition, bool $execute): SeedResult
    {
        $this->startRun($execute);
        $this->seedOwnCompany($definition);
        $this->seedPartners($definition->partners);
        $this->seedSkus($definition->skus);
        $this->seedPurchaseOrders($definition->purchaseOrders);

        return $this->result;
    }

    private function startRun(bool $execute): void
    {
        $this->result = new SeedResult();
        $this->dryRun = ! $execute;
        $this->partners = $this->gateway->partnersByRef();
    }

    private function seedOwnCompany(SeedDefinition $definition): void
    {
        if (! $definition->usesOwnCompany()) {
            return;
        }

        $ownCompany = $this->gateway->ownCompany();
        $this->result->add('partner', SeedDefinition::OWN_COMPANY, SeedResult::EXISTS, "{$ownCompany['name']} (partner number 1)");
        $this->partners[SeedDefinition::OWN_COMPANY] = [
            'id' => $ownCompany['id'],
            'locations' => $ownCompany['locations'],
        ];
        $this->seedLocations(SeedDefinition::OWN_COMPANY, $definition->ownCompanyLocations);
    }

    /** @param list<array<string, mixed>> $partners */
    private function seedPartners(array $partners): void
    {
        foreach ($partners as $partner) {
            $this->seedPartner($partner);
        }
    }

    /** @param array<string, mixed> $partner */
    private function seedPartner(array $partner): void
    {
        $ref = (string) $partner['ref'];
        $locations = $this->locationsOf($partner);

        if (isset($this->partners[$ref])) {
            $this->result->add('partner', $ref, SeedResult::EXISTS);
        } elseif ($this->dryRun) {
            $this->partners[$ref] = ['id' => null, 'locations' => []];
            $this->result->add('partner', $ref, SeedResult::WOULD_CREATE, (string) $partner['name']);
        } else {
            $created = $this->createPartner($ref, $partner);
            if (! $created) {
                $this->reportLocationsNotCreated($ref, $locations);

                return;
            }
        }

        $this->seedLocations($ref, $locations);
    }

    /**
     * @param array<string, mixed> $partner
     * @return bool Whether the partner was created
     */
    private function createPartner(string $ref, array $partner): bool
    {
        $payload = $partner;
        unset($payload['locations']);
        // Order import needs an owner; default to the user running the seed.
        if ($this->ownerId !== null && ! isset($payload['owned_by_id'])) {
            $payload['owned_by_id'] = $this->ownerId;
        }

        try {
            $id = $this->gateway->createPartner($payload);
        } catch (Throwable $exception) {
            $this->result->add('partner', $ref, SeedResult::FAILED, $exception->getMessage());

            return false;
        }

        $this->partners[$ref] = ['id' => $id, 'locations' => []];
        $this->result->add('partner', $ref, SeedResult::CREATED, (string) $partner['name']);

        return true;
    }

    /**
     * @param array<string, mixed> $partner
     * @return list<array<string, mixed>>
     */
    private function locationsOf(array $partner): array
    {
        if (! isset($partner['locations'])) {
            return [];
        }

        return $partner['locations'];
    }

    /** @param list<array<string, mixed>> $locations */
    private function reportLocationsNotCreated(string $partnerRef, array $locations): void
    {
        foreach ($locations as $location) {
            $this->result->add('location', "{$partnerRef}/{$location['ref']}", SeedResult::FAILED, 'Partner was not created.');
        }
    }

    /** @param list<array<string, mixed>> $locations */
    private function seedLocations(string $partnerRef, array $locations): void
    {
        foreach ($locations as $location) {
            $this->seedLocation($partnerRef, $location);
        }
    }

    /** @param array<string, mixed> $location */
    private function seedLocation(string $partnerRef, array $location): void
    {
        $locationRef = (string) $location['ref'];
        $key = "{$partnerRef}/{$locationRef}";
        $partnerId = $this->partners[$partnerRef]['id'];

        if ($this->locationIsKnown($partnerRef, $locationRef)) {
            $this->result->add('location', $key, SeedResult::EXISTS);

            return;
        }
        if ($this->dryRun || $partnerId === null) {
            $this->partners[$partnerRef]['locations'][$locationRef] = null;
            $this->result->add('location', $key, SeedResult::WOULD_CREATE, (string) $location['name']);

            return;
        }

        try {
            $locationId = $this->gateway->createLocation($partnerId, $location);
        } catch (Throwable $exception) {
            $this->result->add('location', $key, SeedResult::FAILED, $exception->getMessage());

            return;
        }
        $this->partners[$partnerRef]['locations'][$locationRef] = $locationId;
        $this->result->add('location', $key, SeedResult::CREATED, (string) $location['name']);
    }

    private function partnerIsKnown(string $partnerRef): bool
    {
        return isset($this->partners[$partnerRef]);
    }

    private function locationIsKnown(string $partnerRef, string $locationRef): bool
    {
        // array_key_exists, not isset: a location planned in a dry run has the ID null.
        return array_key_exists($locationRef, $this->partners[$partnerRef]['locations']);
    }

    /** @param list<array<string, mixed>> $skus */
    private function seedSkus(array $skus): void
    {
        $this->skuCodes = $this->gateway->skuCodes();
        foreach ($skus as $sku) {
            $this->seedSku($sku);
        }
    }

    /** @param array<string, mixed> $sku */
    private function seedSku(array $sku): void
    {
        $code = (string) $sku['sku_code'];
        $name = '';
        if (isset($sku['global_name'])) {
            $name = (string) $sku['global_name'];
        }

        if (isset($this->skuCodes[$code])) {
            $this->result->add('sku', $code, SeedResult::EXISTS);

            return;
        }
        if ($this->dryRun) {
            $this->skuCodes[$code] = true;
            $this->result->add('sku', $code, SeedResult::WOULD_CREATE, $name);

            return;
        }

        try {
            $this->gateway->createSku($sku);
        } catch (Throwable $exception) {
            $this->result->add('sku', $code, SeedResult::FAILED, $exception->getMessage());

            return;
        }
        $this->skuCodes[$code] = true;
        $this->result->add('sku', $code, SeedResult::CREATED, $name);
    }

    /** @param list<array<string, mixed>> $orders */
    private function seedPurchaseOrders(array $orders): void
    {
        foreach ($orders as $order) {
            $this->seedPurchaseOrder($order);
        }
    }

    /** @param array<string, mixed> $order */
    private function seedPurchaseOrder(array $order): void
    {
        $extRef = (string) $order['ext_ref'];

        $problems = $this->problemsWith($order);
        if ($problems !== []) {
            $this->result->add('purchase_order', $extRef, SeedResult::INVALID, implode('; ', $problems).'.');

            return;
        }

        $ids = $this->partnerAndLocationIds($order);
        $allCreated = $this->allIdsKnown($ids);
        $detail = $this->describePurchaseOrder($order);

        if ($allCreated && $this->gateway->purchaseOrderExists($extRef)) {
            $this->result->add('purchase_order', $extRef, SeedResult::EXISTS);

            return;
        }
        if ($this->dryRun) {
            $this->result->add('purchase_order', $extRef, SeedResult::WOULD_CREATE, $detail);

            return;
        }
        if (! $allCreated) {
            $this->result->add('purchase_order', $extRef, SeedResult::FAILED, 'A partner or location it uses was not created.');

            return;
        }

        try {
            $this->gateway->createPurchaseOrder($this->purchaseOrderPayload($order, $ids));
        } catch (Throwable $exception) {
            $this->result->add('purchase_order', $extRef, SeedResult::FAILED, $exception->getMessage());

            return;
        }
        $this->result->add('purchase_order', $extRef, SeedResult::CREATED, $detail);
    }

    /**
     * Partners, locations, and SKUs the order refers to that are neither in
     * tracezilla nor in the seed file.
     *
     * @param array<string, mixed> $order
     * @return list<string>
     */
    private function problemsWith(array $order): array
    {
        $problems = [];
        foreach (['supplier', 'deliver_to'] as $role) {
            $partnerRef = (string) $order[$role]['partner'];
            $locationRef = (string) $order[$role]['location'];
            if (! $this->partnerIsKnown($partnerRef)) {
                $problems[] = "{$role} partner [{$partnerRef}] is not in tracezilla or the seed file";
            } elseif (! $this->locationIsKnown($partnerRef, $locationRef)) {
                $problems[] = "{$role} location [{$partnerRef}/{$locationRef}] is not in tracezilla or the seed file";
            }
        }
        foreach ($order['lines'] as $line) {
            $skuCode = (string) $line['sku_code'];
            if (! isset($this->skuCodes[$skuCode])) {
                $problems[] = "SKU [{$skuCode}] is not in tracezilla or the seed file";
            }
        }

        return $problems;
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, ?string>
     */
    private function partnerAndLocationIds(array $order): array
    {
        $supplier = $order['supplier'];
        $deliverTo = $order['deliver_to'];

        return [
            'supplier' => $this->partners[$supplier['partner']]['id'],
            'supplier_location' => $this->partners[$supplier['partner']]['locations'][$supplier['location']],
            'deliver_to' => $this->partners[$deliverTo['partner']]['id'],
            'deliver_to_location' => $this->partners[$deliverTo['partner']]['locations'][$deliverTo['location']],
        ];
    }

    /**
     * Whether every partner and location already exists in tracezilla. In a
     * dry run, records that would be created have no ID yet.
     *
     * @param array<string, ?string> $ids
     */
    private function allIdsKnown(array $ids): bool
    {
        foreach ($ids as $id) {
            if ($id === null) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $order */
    private function describePurchaseOrder(array $order): string
    {
        $detail = count($order['lines']).' lines';
        if ($this->isReceived($order)) {
            $detail .= ', received';
        }

        return $detail;
    }

    /** @param array<string, mixed> $order */
    private function isReceived(array $order): bool
    {
        if (! isset($order['receive'])) {
            return false;
        }

        return $order['receive'] === true;
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, ?string> $ids
     * @return array<string, mixed>
     */
    private function purchaseOrderPayload(array $order, array $ids): array
    {
        $date = $this->today->format('Y-m-d');
        $supplier = ['partner_id' => $ids['supplier'], 'location_id' => $ids['supplier_location']];
        $deliverTo = ['partner_id' => $ids['deliver_to'], 'location_id' => $ids['deliver_to_location']];

        $afterSaving = 'none';
        if ($this->isReceived($order)) {
            $afterSaving = 'receive';
        }

        return [
            'order_header' => [
                'ext_ref' => $order['ext_ref'],
                'currency' => $order['currency'],
                'exchange_rate' => $order['exchange_rate'],
                'order_date' => $date,
                'pickup_date' => $date,
                'delivery_date' => $date,
                'status' => 'draft',
                'partners' => [
                    'supplier' => $supplier,
                    'pickup_from' => $supplier,
                    'deliver_to' => $deliverTo,
                ],
            ],
            'inbound_lots' => $order['lines'],
            'price_logic' => 'none',
            'post_save_action' => $afterSaving,
            'ignore_missing_skus' => false,
        ];
    }
}
