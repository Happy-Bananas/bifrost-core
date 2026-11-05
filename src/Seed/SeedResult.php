<?php

declare(strict_types=1);

namespace Bifrost\Core\Seed;

/**
 * What the seed did with each record: created, would create, exists, invalid,
 * or failed.
 */
final class SeedResult
{
    public const WOULD_CREATE = 'would_create';
    public const CREATED = 'created';
    public const EXISTS = 'exists';
    public const INVALID = 'invalid';
    public const FAILED = 'failed';

    /** @var list<array{type: string, key: string, action: string, detail: string}> */
    private array $items = [];

    public function add(string $type, string $key, string $action, string $detail = ''): void
    {
        $this->items[] = ['type' => $type, 'key' => $key, 'action' => $action, 'detail' => $detail];
    }

    /** @return list<array{type: string, key: string, action: string, detail: string}> */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * How many records got each action, for example ['exists' => 14, 'created' => 1].
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];
        foreach ($this->items as $item) {
            $action = $item['action'];
            if (! isset($counts[$action])) {
                $counts[$action] = 0;
            }
            $counts[$action]++;
        }

        return $counts;
    }

    /** Whether no record failed and none was invalid. */
    public function isSuccessful(): bool
    {
        foreach ($this->items as $item) {
            if ($item['action'] === self::FAILED) {
                return false;
            }
            if ($item['action'] === self::INVALID) {
                return false;
            }
        }

        return true;
    }
}
