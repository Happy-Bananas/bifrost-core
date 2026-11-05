<?php

declare(strict_types=1);

namespace Bifrost\Core\Seed;

use Bifrost\Core\Tracezilla\Client;
use Bifrost\Core\Tracezilla\SkuCreator;
use RuntimeException;

/**
 * The seed's access to tracezilla: it looks up existing records and creates
 * missing ones through the tracezilla API.
 */
final readonly class TracezillaSeedGateway implements SeedGateway
{
    public function __construct(private Client $client)
    {
    }

    public function partnersByRef(): array
    {
        $partners = [];
        foreach ($this->client->getAll('/partners', ['include' => 'locations', 'perPage' => 250]) as $partner) {
            $ref = $this->trimmedText($partner, 'ref');
            if ($ref === '') {
                continue;
            }
            // Never silently pick one of several matching records.
            if (isset($partners[$ref])) {
                throw new RuntimeException("Several tracezilla partners have ref [{$ref}].");
            }
            $partners[$ref] = [
                'id' => (string) $partner['id'],
                'locations' => $this->locationsByRef($partner, $ref),
            ];
        }

        return $partners;
    }

    public function ownCompany(): array
    {
        foreach ($this->client->getAll('/partners', ['include' => 'locations', 'perPage' => 250]) as $partner) {
            if ($this->isPartnerNumberOne($partner)) {
                $name = $this->trimmedText($partner, 'name');

                return [
                    'id' => (string) $partner['id'],
                    'name' => $name,
                    'locations' => $this->locationsByRef($partner, $name),
                ];
            }
        }

        throw new RuntimeException('tracezilla has no partner number 1, which is the own company of every tracezilla company.');
    }

    public function skuCodes(): array
    {
        $codes = [];
        foreach ($this->client->getAll('/skus', ['perPage' => 250]) as $sku) {
            $code = $this->trimmedText($sku, 'sku_code');
            if ($code !== '') {
                $codes[$code] = true;
            }
        }

        return $codes;
    }

    public function purchaseOrderExists(string $extRef): bool
    {
        $page = $this->client->get('/orders/purchase', ['ext_ref' => ['eq' => $extRef]]);
        if (! isset($page['data'])) {
            return false;
        }

        foreach ($page['data'] as $order) {
            if (isset($order['ext_ref']) && $order['ext_ref'] === $extRef) {
                return true;
            }
        }

        return false;
    }

    public function createPartner(array $payload): string
    {
        $response = $this->client->post('/partners', $payload);

        return $this->createdId($response, 'partner');
    }

    public function createLocation(string $partnerId, array $payload): string
    {
        $response = $this->client->post("/partners/{$partnerId}/locations", $payload);

        return $this->createdId($response, 'location');
    }

    public function createSku(array $payload): void
    {
        $creator = new SkuCreator($this->client);
        $creator->create($payload);
    }

    public function createPurchaseOrder(array $payload): void
    {
        $this->client->put('/orders/purchase', $payload);
    }

    /** @param array<string, mixed> $partner */
    private function isPartnerNumberOne(array $partner): bool
    {
        if (! isset($partner['number'])) {
            return false;
        }

        return (int) $partner['number'] === 1;
    }

    /**
     * @param array<string, mixed> $partner
     * @return array<string, string>
     */
    private function locationsByRef(array $partner, string $partnerLabel): array
    {
        if (! isset($partner['locations'])) {
            return [];
        }

        $locations = [];
        foreach ($partner['locations'] as $location) {
            $ref = $this->trimmedText($location, 'ref');
            if ($ref === '') {
                continue;
            }
            if (isset($locations[$ref])) {
                throw new RuntimeException("tracezilla partner [{$partnerLabel}] has several locations with ref [{$ref}].");
            }
            $locations[$ref] = (string) $location['id'];
        }

        return $locations;
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
    private function createdId(array $response, string $type): string
    {
        $data = $this->dataOf($response);
        if (! isset($data['id']) || ! is_scalar($data['id'])) {
            throw new RuntimeException("tracezilla did not return an ID for the created {$type}.");
        }
        $id = (string) $data['id'];
        if ($id === '') {
            throw new RuntimeException("tracezilla did not return an ID for the created {$type}.");
        }

        return $id;
    }

    /**
     * The value under $key as trimmed text, or '' if it is missing.
     *
     * @param array<string, mixed> $record
     */
    private function trimmedText(array $record, string $key): string
    {
        if (! isset($record[$key])) {
            return '';
        }

        return trim((string) $record[$key]);
    }
}
