<?php

declare(strict_types=1);

namespace Tests\Unit\Seed;

use RuntimeException;
use Bifrost\Core\Seed\SeedGateway;

final class InMemorySeedGateway implements SeedGateway
{
    /** @var array<string, array{id: string, locations: array<string, string>}> */
    public array $partners = [];

    /** @var array<string, array{id: string, locations: array<string, string>}> Keyed by name. */
    public array $companies = ['Own Company' => ['id' => 'own', 'locations' => []]];

    /** @var array<string, true> */
    public array $skus = [];

    /** @var array<string, array<string, mixed>> */
    public array $purchaseOrders = [];

    /** @var list<string> */
    public array $writes = [];

    /** @var array<string, array<string, mixed>> */
    public array $partnerPayloads = [];

    /** @var array<string, string> SKU codes or partner refs whose creation fails. */
    public array $failures = [];

    private int $nextId = 1;

    public function partnersByRef(): array
    {
        return $this->partners;
    }

    public function ownCompany(string $name): array
    {
        if (! isset($this->companies[$name])) {
            throw new RuntimeException("No tracezilla partner is named [{$name}].");
        }

        return $this->companies[$name];
    }

    public function skuCodes(): array
    {
        return $this->skus;
    }

    public function purchaseOrderExists(string $extRef): bool
    {
        return isset($this->purchaseOrders[$extRef]);
    }

    public function createPartner(array $payload): string
    {
        $this->failIfConfigured($payload['ref']);
        $this->writes[] = "partner {$payload['ref']}";
        $this->partnerPayloads[$payload['ref']] = $payload;
        $id = 'partner-'.$this->nextId++;
        $this->partners[$payload['ref']] = ['id' => $id, 'locations' => []];

        return $id;
    }

    public function createLocation(string $partnerId, array $payload): string
    {
        $this->writes[] = "location {$payload['ref']}";
        $id = 'location-'.$this->nextId++;
        foreach ($this->partners as $ref => $partner) {
            if ($partner['id'] === $partnerId) {
                $this->partners[$ref]['locations'][$payload['ref']] = $id;
            }
        }
        foreach ($this->companies as $name => $company) {
            if ($company['id'] === $partnerId) {
                $this->companies[$name]['locations'][$payload['ref']] = $id;
            }
        }

        return $id;
    }

    public function createSku(array $payload): void
    {
        $this->failIfConfigured($payload['sku_code']);
        $this->writes[] = "sku {$payload['sku_code']}";
        $this->skus[$payload['sku_code']] = true;
    }

    public function createPurchaseOrder(array $payload): void
    {
        $extRef = $payload['order_header']['ext_ref'];
        $this->writes[] = "purchase_order {$extRef}";
        $this->purchaseOrders[$extRef] = $payload;
    }

    private function failIfConfigured(string $key): void
    {
        if (isset($this->failures[$key])) {
            throw new RuntimeException($this->failures[$key]);
        }
    }
}
