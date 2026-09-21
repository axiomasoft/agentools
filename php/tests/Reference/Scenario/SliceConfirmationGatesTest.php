<?php

declare(strict_types=1);

namespace AgenTools\Tests\Reference\Scenario;

use AgenTools\Confirmation\ConfirmationProtocol;
use AgenTools\Reference\Adapters\DeliveryCollector;
use AgenTools\Reference\Adapters\RegistryToolPort;
use AgenTools\Reference\Crypto\TenantHmacTokenSigner;
use AgenTools\Reference\Scenario\ConfirmationMetrics;
use AgenTools\Reference\Scenario\PermissionCommitAuthorizer;
use AgenTools\Reference\Scenario\ReferenceConfirmationServiceFactory;
use AgenTools\Reference\Scenario\ReferenceIdempotencyCoordinatorFactory;
use AgenTools\Reference\Scenario\SliceToolCoordinator;
use AgenTools\Reference\Support\InjectableClock;
use AgenTools\Reference\Tools\DayReportStore;
use AgenTools\Reference\Tools\ReferenceSliceToolFactory;
use AgenTools\Schema\Canonicalizer;
use AgenTools\Tool\ToolDescriptor;
use PHPUnit\Framework\TestCase;

final class SliceConfirmationGatesTest extends TestCase
{
    public function testItRejectsArgsMismatchOnCommit(): void
    {
        $coordinator = $this->coordinator();
        $args = ['report_id' => 'r-1', 'date' => '2026-09-19', 'body' => 'shown'];
        $coordinator->invoke('slice_write_day_report', $args, 'idem-1', null);
        $result = $coordinator->invoke('slice_write_day_report', ['report_id' => 'r-1', 'date' => '2026-09-19', 'body' => 'changed'], 'idem-1', 'approve');

        self::assertTrue($result->isError());
        self::assertSame('agentools.precondition.confirmation_args_mismatch', $result->error?->code);
    }

    public function testItRejectsExpiredToken(): void
    {
        $clock = new InjectableClock(new \DateTimeImmutable('2026-09-19T10:00:00+00:00'));
        $coordinator = $this->coordinator($clock);
        $args = ['report_id' => 'r-2', 'date' => '2026-09-19', 'body' => 'body'];
        $coordinator->invoke('slice_write_day_report', $args, 'idem-2', null);
        $clock->advance('PT3H');
        $result = $coordinator->invoke('slice_write_day_report', $args, 'idem-2-commit', 'approve');

        self::assertTrue($result->isError());
        self::assertSame('agentools.precondition.confirmation_expired', $result->error?->code);
    }

    public function testItRejectsSpentToken(): void
    {
        $clock = new InjectableClock(new \DateTimeImmutable('2026-09-19T10:00:00+00:00'));
        $port = $this->port();
        $bundle = (new ReferenceConfirmationServiceFactory())->bundle($port, $clock, 'tenant-a', 'user-a', ['slice.write']);
        $args = ['report_id' => 'r-3', 'date' => '2026-09-19', 'body' => 'body'];
        $token = $bundle->service->prepare('act-1', 'slice_write_day_report', $args, ['summary' => 's', 'changes' => []]);
        $bundle->service->commit($token->value, $args);
        $this->expectException(\AgenTools\Confirmation\ConfirmationFailure::class);
        $bundle->service->commit($token->value, $args);
    }

    public function testItSurfacesDescriptorDrift(): void
    {
        $port = $this->port();
        $clock = new InjectableClock(new \DateTimeImmutable('2026-09-19T10:00:00+00:00'));
        $service = (new ReferenceConfirmationServiceFactory())->create($port, $clock, 'tenant-a', 'user-a', ['slice.write']);
        $coordinator = new SliceToolCoordinator(
            $port,
            ReferenceIdempotencyCoordinatorFactory::create($port, 'tenant-a', 'act-drift', static fn (): \DateTimeImmutable => $clock->now()),
            $service,
            new DeliveryCollector('tenant-a'),
            new ConfirmationMetrics(),
            new Canonicalizer(),
            'act-drift',
        );

        $args = ['report_id' => 'r-4', 'date' => '2026-09-19', 'body' => 'body'];
        $coordinator->invoke('slice_write_day_report', $args, 'idem-4', null);

        $factory = new ReferenceSliceToolFactory(new DayReportStore());
        $base = $factory->writeDescriptor()->toArray();
        $schema = $base['inputSchema'];
        self::assertIsArray($schema);
        self::assertIsArray($schema['properties'] ?? null);
        $schema['properties']['revision'] = ['type' => 'string'];
        $base['inputSchema'] = $schema;
        $port->register(ToolDescriptor::fromArray($base), static fn (array $arguments, string $idempotencyKey): \AgenTools\Error\ToolExecutionResult => \AgenTools\Error\ToolExecutionResult::success(['report' => []]));

        $result = $coordinator->invoke('slice_write_day_report', $args, 'idem-4-commit', 'approve');

        self::assertTrue($result->isError());
        self::assertSame('agentools.precondition.descriptor_drift', $result->error?->code);
    }

    private function coordinator(?InjectableClock $clock = null): SliceToolCoordinator
    {
        $clock ??= new InjectableClock(new \DateTimeImmutable('2026-09-19T10:00:00+00:00'));
        $port = $this->port();
        $service = (new ReferenceConfirmationServiceFactory())->create($port, $clock, 'tenant-a', 'user-a', ['slice.write']);

        return new SliceToolCoordinator(
            $port,
            ReferenceIdempotencyCoordinatorFactory::create($port, 'tenant-a', 'act-test', static fn (): \DateTimeImmutable => $clock->now()),
            $service,
            new DeliveryCollector('tenant-a'),
            new ConfirmationMetrics(),
            new Canonicalizer(),
            'act-test',
        );
    }

    private function port(): RegistryToolPort
    {
        $port = new RegistryToolPort();
        (new ReferenceSliceToolFactory(new DayReportStore()))->registerOn($port, 'tenant-a');
        $port->bindSubject(
            ['initiator' => ['id' => 'user-a'], 'agent' => ['id' => 'agent-a'], 'authorizer' => ['id' => 'user-a']],
            ['resource' => 'day-report', 'tool' => 'slice_write_day_report', 'permissions' => ['slice.read', 'slice.draft', 'slice.write']],
        );

        return $port;
    }
}
