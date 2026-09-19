<?php

declare(strict_types=1);

namespace AgenTools\Reference\Cli;

use AgenTools\Reference\Adapters\DeliveryCollector;

/** Tenant-scoped consumer that renders newly delivered protocol objects. */
final class DeliveryConsumer
{
    private int $cursor = 0;

    public function __construct(
        private readonly DeliveryRenderer $renderer,
        private readonly string $sessionTenantId,
    ) {
    }

    public function drain(DeliveryCollector $collector): string
    {
        if ($collector->tenantId() !== $this->sessionTenantId) {
            return "WRONG_TENANT: deliveries are scoped to {$collector->tenantId()}, session expects {$this->sessionTenantId}\n";
        }

        $objects = $collector->collected();
        $output = '';
        for ($index = $this->cursor, $count = count($objects); $index < $count; ++$index) {
            $output .= $this->renderer->render($objects[$index]);
        }
        $this->cursor = count($objects);

        return $output;
    }

    public function reset(): void
    {
        $this->cursor = 0;
    }
}
