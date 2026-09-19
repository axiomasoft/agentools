<?php

declare(strict_types=1);

namespace AgenTools\Reference\Adapters;

/** Tenant-scoped in-process collector for protocol objects delivered without interpretation. */
final class DeliveryCollector
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $events = [];

    public function __construct(private readonly string $tenantId)
    {
        if ($tenantId === '') {
            throw new \InvalidArgumentException('tenant_id must not be empty.');
        }
    }

    /** @param array<string, mixed> $object */
    public function deliver(array $object): void
    {
        if (!isset($this->events[$this->tenantId])) {
            $this->events[$this->tenantId] = [];
        }

        $this->events[$this->tenantId][] = $object;
    }

    /** @return list<array<string, mixed>> */
    public function collected(): array
    {
        return $this->events[$this->tenantId] ?? [];
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }
}
