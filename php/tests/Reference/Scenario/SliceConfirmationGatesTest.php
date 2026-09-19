<?php

declare(strict_types=1);

namespace AgenTools\Tests\Reference\Scenario;

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
use AgenTools\Tool\ToolDescriptor;
use PHPUnit\Framework\TestCase;

final class SliceConfirmationGatesTest extends TestCase
{
    /** Negative: swapped arguments after preview are rejected at commit. */
    public function testItRejectsArgsMismatchOnCommit(): void
    {
        $coordinator = $this->coordinator();
        $args = ['report_id' => 'r-1', 'date' => '2026-09-19', 'body' => 'shown'];
        $coordinator->invoke('slice_write_day_report', $args, 'idem-1', null);
        $result = $coordinator->invoke('slice_write_day_report', ['report_id' => 'r-1', 'date' => '2026-09-19', 'body' => 'changed'], 'idem-1', 'approve');

        self::assertTrue($result->isError());
        self::assertSame('agentools.precondition.confirmation_args_mismatch', $result->error?->code);
    }

    /** Negative: expired token surfaces as tool execution error. */
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

    /** Negative: presenting the same spent token again is rejected. */
    public function testItRejectsSpentToken(): void
    {
        $clock = new InjectableClock(new \DateTimeImmutable('2026-09-19T10:00:00+00:00'));
        $protocol = new ConfirmationProtocol(new TenantHmacTokenSigner('tenant-a', 'reference-slice-secret'));
        $coordinator = $this->coordinator($clock, $protocol);
        $args = ['report_id' => 'r-3', 'date' => '2026-09-19', 'body' => 'body'];
        $coordinator->invoke('slice_write_day_report', $args, 'idem-3', null);
        $token = (string) $coordinator->pendingTokenValue();
        self::assertFalse($coordinator->invoke('slice_write_day_report', $args, 'idem-3-commit', 'approve')->isError());

        $canonicalizer = new Canonicalizer();
        $factory = new ReferenceSliceToolFactory(new DayReportStore());
        $fingerprint = \AgenTools\Idempotency\ArgumentsFingerprint::fromArguments($args, $canonicalizer);
        $digest = \AgenTools\Tool\DescriptorDigest::for($factory->writeDescriptor(), $canonicalizer);
        $authorizer = new PermissionCommitAuthorizer(['slice.write']);

        try {
            $protocol->commit($token, $fingerprint, $digest, 'user-a', $clock->now(), $authorizer);
            self::fail('Expected a spent confirmation token.');
        } catch (\AgenTools\Confirmation\ConfirmationFailure $failure) {
            self::assertSame('agentools.precondition.confirmation_token_spent', $failure->error->code);
        }
    }

    /** Negative: descriptor drift between prepare and commit. */
    public function testItSurfacesDescriptorDrift(): void
    {
        $port = $this->port();
        $coordinator = new SliceToolCoordinator(
            $port,
            new ConfirmationProtocol(new TenantHmacTokenSigner('tenant-a', 'reference-slice-secret')),
            new DeliveryCollector('tenant-a'),
            new ConfirmationMetrics(),
            new Canonicalizer(),
            new InjectableClock(new \DateTimeImmutable('2026-09-19T10:00:00+00:00')),
            'act-drift',
            'user-a',
            new PermissionCommitAuthorizer(['slice.write']),
        );

        $args = ['report_id' => 'r-4', 'date' => '2026-09-19', 'body' => 'body'];
        $coordinator->invoke('slice_write_day_report', $args, 'idem-4', null);

        $factory = new ReferenceSliceToolFactory(new DayReportStore());
        $base = $factory->writeDescriptor()->toArray();
        if (!isset($base['inputSchema']) || !is_array($base['inputSchema'])) {
            self::fail('Expected inputSchema on descriptor.');
        }
        $schema = $base['inputSchema'];
        if (!isset($schema['properties']) || !is_array($schema['properties'])) {
            self::fail('Expected inputSchema.properties on descriptor.');
        }
        $schema['properties']['revision'] = ['type' => 'string'];
        $base['inputSchema'] = $schema;
        $port->register(ToolDescriptor::fromArray($base), static fn (array $arguments, string $idempotencyKey): \AgenTools\Error\ToolExecutionResult => \AgenTools\Error\ToolExecutionResult::success(['report' => []]));

        $result = $coordinator->invoke('slice_write_day_report', $args, 'idem-4-commit', 'approve');

        self::assertTrue($result->isError());
        self::assertSame('agentools.precondition.descriptor_drift', $result->error?->code);
    }

    private function coordinator(?InjectableClock $clock = null, ?ConfirmationProtocol $protocol = null): SliceToolCoordinator
    {
        return new SliceToolCoordinator(
            $this->port(),
            $protocol ?? new ConfirmationProtocol(new TenantHmacTokenSigner('tenant-a', 'reference-slice-secret')),
            new DeliveryCollector('tenant-a'),
            new ConfirmationMetrics(),
            new Canonicalizer(),
            $clock ?? new InjectableClock(new \DateTimeImmutable('2026-09-19T10:00:00+00:00')),
            'act-test',
            'user-a',
            new PermissionCommitAuthorizer(['slice.write']),
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
