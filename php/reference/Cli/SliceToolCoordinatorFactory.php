<?php

declare(strict_types=1);

namespace AgenTools\Reference\Cli;

use AgenTools\Reference\Adapters\DeliveryCollector;
use AgenTools\Reference\Adapters\RegistryToolPort;
use AgenTools\Reference\Scenario\ConfirmationMetrics;
use AgenTools\Reference\Scenario\ReferenceConfirmationServiceFactory;
use AgenTools\Reference\Scenario\ReferenceIdempotencyCoordinatorFactory;
use AgenTools\Reference\Scenario\SliceToolCoordinator;
use AgenTools\Reference\Support\InjectableClock;
use AgenTools\Reference\Tools\DayReportStore;
use AgenTools\Reference\Tools\ReferenceSliceToolFactory;
use AgenTools\Schema\Canonicalizer;

final class SliceToolCoordinatorFactory
{
    public function create(string $tenantId, InjectableClock $clock, string $actionId): CoordinatorBundle
    {
        $collector = new DeliveryCollector($tenantId);
        $port = new RegistryToolPort();
        (new ReferenceSliceToolFactory(new DayReportStore()))->registerOn($port, $tenantId);
        $port->bindSubject(
            ['initiator' => ['id' => 'user-a'], 'agent' => ['id' => 'agent-a'], 'authorizer' => ['id' => 'user-a']],
            ['resource' => 'day-report', 'tool' => 'slice_write_day_report', 'permissions' => ['slice.read', 'slice.draft', 'slice.write']],
        );
        $service = (new ReferenceConfirmationServiceFactory())->create($port, $clock, $tenantId, 'user-a', ['slice.write']);
        $idempotency = ReferenceIdempotencyCoordinatorFactory::create(
            $port,
            $tenantId,
            $actionId,
            static fn (): \DateTimeImmutable => $clock->now(),
        );

        return new CoordinatorBundle(
            new SliceToolCoordinator($port, $idempotency, $service, $collector, new ConfirmationMetrics(), new Canonicalizer(), $actionId),
            $collector,
        );
    }
}

final readonly class CoordinatorBundle
{
    public function __construct(
        public SliceToolCoordinator $coordinator,
        public DeliveryCollector $collector,
    ) {
    }
}
