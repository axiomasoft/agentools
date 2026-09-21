<?php

declare(strict_types=1);

namespace AgenTools\Reference\Scenario;

use AgenTools\Idempotency\DeduplicationPolicy;
use AgenTools\Idempotency\IdempotencyCoordinator;
use AgenTools\TestKit\InMemoryDeduplicationStore;
use AgenTools\Idempotency\ToolIdempotencyCapability;
use AgenTools\Idempotency\ToolIdempotencyCatalog;
use AgenTools\Reference\Adapters\RegistryToolPort;
use AgenTools\Schema\Canonicalizer;

final class ReferenceIdempotencyCoordinatorFactory
{
    public static function create(
        RegistryToolPort $port,
        string $tenantId,
        string $actionId,
        callable $clock,
    ): IdempotencyCoordinator {
        return new IdempotencyCoordinator(
            new InMemoryDeduplicationStore(new DeduplicationPolicy(new \DateInterval('P1D'), new \DateInterval('PT0S'), new \DateInterval('PT0S'))),
            new ToolIdempotencyCatalog(
                [
                    'slice_read_day_activities' => ToolIdempotencyCapability::AtMostOnceOnly,
                    'slice_draft_day_report' => ToolIdempotencyCapability::AtMostOnceOnly,
                    'slice_write_day_report' => ToolIdempotencyCapability::NativeKey,
                ],
                [
                    'slice_read_day_activities' => 'read',
                    'slice_draft_day_report' => 'draft',
                    'slice_write_day_report' => 'write',
                ],
            ),
            $port,
            new Canonicalizer(),
            $tenantId,
            $actionId,
            clock: $clock(...),
        );
    }
}
