<?php

declare(strict_types=1);

namespace Bifrost\Core\Seed;

use InvalidArgumentException;

/**
 * Checks a seed file before anything is written. It collects every problem, so
 * the developer can fix them all at once, and then reports them together.
 */
final class SeedFileValidator
{
    private const TOP_LEVEL_KEYS = ['version', 'own_company', 'partners', 'skus', 'purchase_orders'];

    /** @var list<string> */
    private array $errors = [];

    /** @param array<string, mixed> $data */
    public function validate(array $data): void
    {
        $this->errors = [];

        $this->checkVersion($data);
        $this->checkTopLevelKeys($data);
        $this->checkOwnCompany($data);
        $this->checkPartners($this->listAt($data, 'partners', ''));
        $this->checkSkus($this->listAt($data, 'skus', ''));
        $this->checkPurchaseOrders($this->listAt($data, 'purchase_orders', ''));

        if ($this->errors !== []) {
            throw new InvalidArgumentException("Invalid seed definition:\n- ".implode("\n- ", $this->errors));
        }
    }

    /** @param array<string, mixed> $data */
    private function checkVersion(array $data): void
    {
        if (! isset($data['version']) || $data['version'] !== SeedDefinition::VERSION) {
            $this->errors[] = 'version must be '.SeedDefinition::VERSION.'.';
        }
    }

    /** @param array<string, mixed> $data */
    private function checkTopLevelKeys(array $data): void
    {
        foreach (array_keys($data) as $key) {
            if (! in_array($key, self::TOP_LEVEL_KEYS, true)) {
                $this->errors[] = "Unknown top-level key [{$key}].";
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function checkOwnCompany(array $data): void
    {
        if (! isset($data['own_company'])) {
            return;
        }
        $ownCompany = $data['own_company'];
        if (! $this->containsOnlyLocations($ownCompany)) {
            $this->errors[] = 'own_company may only contain locations.';

            return;
        }

        $this->checkLocations($this->listAt($ownCompany, 'locations', 'own_company'), 'own_company');
    }

    private function containsOnlyLocations(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }
        foreach (array_keys($value) as $key) {
            if ($key !== 'locations') {
                return false;
            }
        }

        return true;
    }

    /** @param list<array<string, mixed>> $partners */
    private function checkPartners(array $partners): void
    {
        $this->requireUnique($partners, 'ref', 'partners');
        foreach ($partners as $index => $partner) {
            $at = "partners[{$index}]";
            $this->requireFields($partner, ['ref', 'name', 'country'], $at);
            if (isset($partner['ref']) && $partner['ref'] === SeedDefinition::OWN_COMPANY) {
                $this->errors[] = "{$at}.ref [".SeedDefinition::OWN_COMPANY.'] is reserved for the own company.';
            }
            $this->checkLocations($this->listAt($partner, 'locations', $at), $at);
        }
    }

    /** @param list<array<string, mixed>> $locations */
    private function checkLocations(array $locations, string $at): void
    {
        $this->requireUnique($locations, 'ref', "{$at}.locations");
        foreach ($locations as $index => $location) {
            $this->requireFields($location, ['ref', 'name', 'country'], "{$at}.locations[{$index}]");
        }
    }

    /** @param list<array<string, mixed>> $skus */
    private function checkSkus(array $skus): void
    {
        $this->requireUnique($skus, 'sku_code', 'skus');
        foreach ($skus as $index => $sku) {
            $at = "skus[{$index}]";
            $this->requireFields($sku, ['sku_code', 'unit_of_measure', 'lot_unit', 'weight_factor_net', 'weight_factor_gross'], $at);
            $this->checkSkuCodeLength($sku, $at);
        }
    }

    /** @param array<string, mixed> $sku */
    private function checkSkuCodeLength(array $sku, string $at): void
    {
        if (! isset($sku['sku_code']) || ! is_string($sku['sku_code'])) {
            return;
        }
        if (strlen($sku['sku_code']) > SeedDefinition::MAX_SKU_CODE_LENGTH) {
            $this->errors[] = "{$at}.sku_code [{$sku['sku_code']}] is longer than ".SeedDefinition::MAX_SKU_CODE_LENGTH.' characters.';
        }
    }

    /** @param list<array<string, mixed>> $orders */
    private function checkPurchaseOrders(array $orders): void
    {
        $this->requireUnique($orders, 'ext_ref', 'purchase_orders');
        foreach ($orders as $index => $order) {
            $at = "purchase_orders[{$index}]";
            $this->requireFields($order, ['ext_ref', 'supplier', 'deliver_to', 'currency', 'exchange_rate', 'lines'], $at);
            $this->checkLocationReference($order, 'supplier', $at);
            $this->checkLocationReference($order, 'deliver_to', $at);
            if (isset($order['receive']) && ! is_bool($order['receive'])) {
                $this->errors[] = "{$at}.receive must be true or false.";
            }
            foreach ($this->listAt($order, 'lines', $at) as $lineIndex => $line) {
                $this->requireFields($line, ['sku_code', 'quantity'], "{$at}.lines[{$lineIndex}]");
            }
        }
    }

    /**
     * A location reference looks like { partner: <ref>, location: <ref> }.
     *
     * @param array<string, mixed> $order
     */
    private function checkLocationReference(array $order, string $role, string $at): void
    {
        if (! isset($order[$role])) {
            return;
        }
        if (! $this->isLocationReference($order[$role])) {
            $this->errors[] = "{$at}.{$role} must be { partner: <ref>, location: <ref> }.";
        }
    }

    private function isLocationReference(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }
        if (count($value) !== 2) {
            return false;
        }

        return $this->isFilledText($value, 'partner') && $this->isFilledText($value, 'location');
    }

    /**
     * The list of records under $key. Reports a problem, and returns an empty
     * list, if it is not a list of mappings.
     *
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    private function listAt(array $data, string $key, string $at): array
    {
        $path = ltrim("{$at}.{$key}", '.');
        if (! isset($data[$key])) {
            return [];
        }
        $value = $data[$key];

        if (! is_array($value) || ! array_is_list($value)) {
            $this->errors[] = "{$path} must be a list.";

            return [];
        }
        foreach ($value as $index => $item) {
            if (! is_array($item) || array_is_list($item)) {
                $this->errors[] = "{$path}[{$index}] must be a mapping.";

                return [];
            }
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $record
     * @param list<string> $fields
     */
    private function requireFields(array $record, array $fields, string $at): void
    {
        foreach ($fields as $field) {
            if ($this->isMissing($record, $field)) {
                $this->errors[] = "{$at}.{$field} is required.";
            }
        }
    }

    /** @param array<string, mixed> $record */
    private function isMissing(array $record, string $field): bool
    {
        if (! isset($record[$field])) {
            return true;
        }
        $value = $record[$field];
        if (is_string($value) && trim($value) === '') {
            return true;
        }

        return $value === [];
    }

    /**
     * Report every record whose $field repeats the value of an earlier record.
     *
     * @param list<array<string, mixed>> $records
     */
    private function requireUnique(array $records, string $field, string $at): void
    {
        $seen = [];
        foreach ($records as $index => $record) {
            if (! isset($record[$field]) || ! is_scalar($record[$field])) {
                continue;
            }
            $value = (string) $record[$field];
            if (isset($seen[$value])) {
                $this->errors[] = "{$at}[{$index}] repeats [{$value}].";
            }
            $seen[$value] = true;
        }
    }

    /** @param array<string, mixed> $values */
    private function isFilledText(array $values, string $key): bool
    {
        if (! isset($values[$key])) {
            return false;
        }
        if (! is_string($values[$key])) {
            return false;
        }

        return $values[$key] !== '';
    }
}
