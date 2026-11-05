<?php

declare(strict_types=1);

namespace Bifrost\Core\Tracezilla;

use RuntimeException;

/**
 * Creates an SKU in tracezilla with exactly the units that were asked for.
 *
 * On create, tracezilla locks the unit conversion and derives the lot unit from
 * the unit of measure (pcs -> pcs, kg -> g). When that changes the units, the
 * SKU is updated with the lock removed, and the stored units are checked.
 */
final readonly class SkuCreator
{
    /** Unit fields that tracezilla may replace when it creates an SKU. */
    private const UNIT_FIELDS = ['unit_of_measure', 'lot_unit', 'default_uom_conversion'];

    public function __construct(private Client $client)
    {
    }

    /** @param array<string, mixed> $sku The SKU, with tracezilla API field names */
    public function create(array $sku): void
    {
        $response = $this->client->post('/skus', $sku);
        $created = $this->dataOf($response);
        if ($this->unitsMatch($sku, $created)) {
            return;
        }

        $this->correctUnits($sku, $this->createdId($response));
    }

    /** @param array<string, mixed> $sku */
    private function correctUnits(array $sku, string $skuId): void
    {
        $update = $sku;
        $update['lock_uom_conversion'] = false;

        $response = $this->client->put("/skus/{$skuId}", $update);
        $updated = $this->dataOf($response);
        if ($this->unitsMatch($sku, $updated)) {
            return;
        }

        $stored = json_encode($this->unitsOf($updated));
        $requested = json_encode($this->unitsOf($sku));
        throw new RuntimeException("tracezilla stored units {$stored} instead of {$requested} for SKU [{$sku['sku_code']}].");
    }

    /**
     * @param array<string, mixed> $requested
     * @param array<string, mixed> $stored
     */
    private function unitsMatch(array $requested, array $stored): bool
    {
        foreach (self::UNIT_FIELDS as $field) {
            if (! array_key_exists($field, $requested)) {
                continue;
            }
            $storedValue = '';
            if (isset($stored[$field])) {
                $storedValue = (string) $stored[$field];
            }
            if ((string) $requested[$field] !== $storedValue) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $sku
     * @return array<string, mixed>
     */
    private function unitsOf(array $sku): array
    {
        $units = [];
        foreach (self::UNIT_FIELDS as $field) {
            if (array_key_exists($field, $sku)) {
                $units[$field] = $sku[$field];
            }
        }

        return $units;
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function dataOf(array $response): array
    {
        if (! isset($response['data']) || ! is_array($response['data'])) {
            return [];
        }

        return $response['data'];
    }

    /** @param array<string, mixed> $response */
    private function createdId(array $response): string
    {
        $data = $this->dataOf($response);
        if (! isset($data['id']) || ! is_scalar($data['id'])) {
            throw new RuntimeException('tracezilla did not return an ID for the created SKU.');
        }
        $id = (string) $data['id'];
        if ($id === '') {
            throw new RuntimeException('tracezilla did not return an ID for the created SKU.');
        }

        return $id;
    }
}
