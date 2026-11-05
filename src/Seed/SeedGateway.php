<?php

declare(strict_types=1);

namespace Bifrost\Core\Seed;

/**
 * What the seed needs from tracezilla. The real gateway calls the API; the
 * tests use an in-memory gateway.
 */
interface SeedGateway
{
    /**
     * Existing partners that have a ref, with their locations that have a ref.
     *
     * @return array<string, array{id: string, locations: array<string, string>}>
     */
    public function partnersByRef(): array;

    /**
     * The partner representing the team's own company: partner number 1, which
     * tracezilla creates together with the company.
     *
     * @return array{id: string, name: string, locations: array<string, string>}
     */
    public function ownCompany(): array;

    /** @return array<string, true> */
    public function skuCodes(): array;

    public function purchaseOrderExists(string $extRef): bool;

    /** @return string The new partner ID. */
    public function createPartner(array $payload): string;

    /** @return string The new location ID. */
    public function createLocation(string $partnerId, array $payload): string;

    public function createSku(array $payload): void;

    public function createPurchaseOrder(array $payload): void;
}
