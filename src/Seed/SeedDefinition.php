<?php

declare(strict_types=1);

namespace Bifrost\Core\Seed;

use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

/**
 * A validated seed file. Records keep their tracezilla API field names; only
 * the identity keys and the purchase-order shorthand are seed-specific.
 */
final readonly class SeedDefinition
{
    public const VERSION = 1;

    /** tracezilla rejects longer SKU codes. */
    public const MAX_SKU_CODE_LENGTH = 17;

    /** Partner reference for the team's own company, configured outside the seed file. */
    public const OWN_COMPANY = 'own_company';

    /**
     * @param list<array<string, mixed>> $ownCompanyLocations
     * @param list<array<string, mixed>> $partners
     * @param list<array<string, mixed>> $skus
     * @param list<array<string, mixed>> $purchaseOrders
     */
    public function __construct(
        public array $ownCompanyLocations,
        public array $partners,
        public array $skus,
        public array $purchaseOrders,
    ) {
    }

    public static function fromFile(string $path): self
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("Seed file not found: {$path}");
        }

        $data = Yaml::parseFile($path);
        if (! is_array($data)) {
            throw new InvalidArgumentException("Seed file {$path} must contain a YAML mapping.");
        }

        return self::fromArray($data);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $validator = new SeedFileValidator();
        $validator->validate($data);

        $ownCompany = [];
        if (isset($data['own_company'])) {
            $ownCompany = $data['own_company'];
        }

        return new self(
            self::recordsAt($ownCompany, 'locations'),
            self::recordsAt($data, 'partners'),
            self::recordsAt($data, 'skus'),
            self::recordsAt($data, 'purchase_orders'),
        );
    }

    public function usesOwnCompany(): bool
    {
        if ($this->ownCompanyLocations !== []) {
            return true;
        }
        foreach ($this->purchaseOrders as $order) {
            if ($order['supplier']['partner'] === self::OWN_COMPANY) {
                return true;
            }
            if ($order['deliver_to']['partner'] === self::OWN_COMPANY) {
                return true;
            }
        }

        return false;
    }

    /**
     * The records under $key, or an empty list if the key is missing. The
     * validator has already checked that they are a list of mappings.
     *
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    private static function recordsAt(array $data, string $key): array
    {
        if (! isset($data[$key])) {
            return [];
        }

        return $data[$key];
    }
}
