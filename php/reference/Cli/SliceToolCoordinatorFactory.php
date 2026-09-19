<?php

declare(strict_types=1);

namespace AgenTools\Reference\Cli;

use AgenTools\Confirmation\ConfirmationProtocol;
use AgenTools\Reference\Adapters\DeliveryCollector;
use AgenTools\Reference\Adapters\RegistryToolPort;
use AgenTools\Reference\Crypto\TenantHmacTokenSigner;
use AgenTools\Reference\Scenario\ConfirmationMetrics;
use AgenTools\Reference\Scenario\PermissionCommitAuthorizer;
use AgenTools\Reference\Scenario\SliceToolCoordinator;
use AgenTools\Reference\Support\InjectableClock;
use AgenTools\Reference\Tools\DayReportStore;
use AgenTools\Reference\Tools\ReferenceSliceToolFactory;
use AgenTools\Schema\Canonicalizer;

/** Builds an isolated coordinator + collector pair for CLI negative demonstrations. */
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

        $coordinator = new SliceToolCoordinator(
            $port,
            new ConfirmationProtocol(new TenantHmacTokenSigner($tenantId, 'reference-slice-secret')),
            $collector,
            new ConfirmationMetrics(),
            new Canonicalizer(),
            $clock,
            $actionId,
            'user-a',
            new PermissionCommitAuthorizer(['slice.write']),
        );

        return new CoordinatorBundle($coordinator, $collector);
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
