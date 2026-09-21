<?php

declare(strict_types=1);

namespace AgenTools\Tests\Reference\Cli;

use AgenTools\Reference\Cli\ConfirmationChoice;
use AgenTools\Reference\Cli\ReferenceSliceSession;
use AgenTools\Reference\Support\InjectableClock;
use AgenTools\Reference\Tools\DayReportStore;
use PHPUnit\Framework\TestCase;

final class ReferenceSliceCliTest extends TestCase
{
    /** INT-002 §6; CLI renders deliveries and completes the product vector with one approval. */
    public function testItRunsTextPathWithSingleConfirmation(): void
    {
        $session = new ReferenceSliceSession();
        $path = dirname(__DIR__, 3) . '/../fixtures/product-slice/vectors/l2/product-slice-001.json';
        $loaded = $session->loadProductVector($path);
        $store = new DayReportStore();
        $store->seedActivities('tenant-a', '2026-09-19', [['id' => 'act-1', 'summary' => 'Call']]);
        $script = array_values(array_filter(
            $loaded['script'],
            static fn (array $step): bool => !isset($step['approval']),
        ));

        $result = $session->run(
            $script,
            'cli-test',
            'tenant-a',
            new InjectableClock(new \DateTimeImmutable('2026-09-19T10:00:00+00:00')),
            $store,
            [new ConfirmationChoice('approve', [])],
        );

        $output = $result['output'];
        self::assertStringContainsString('[preview]', $output);
        self::assertStringContainsString('[confirmation]', $output);
        self::assertSame('completed', $result['outcome']['final_state']);
    }

    /** Negative 1/3: consumer refuses a foreign tenant delivery stream. */
    public function testItRejectsWrongTenantDeliveries(): void
    {
        $output = (new ReferenceSliceSession())->demonstrateWrongTenant();

        self::assertStringContainsString('WRONG_TENANT', $output);
    }

    /** Negative 2/3: expired preview TTL surfaces confirmation_expired. */
    public function testItSurfacesExpiredPreviewTtl(): void
    {
        $clock = new InjectableClock(new \DateTimeImmutable('2026-09-19T10:00:00+00:00'));
        $output = (new ReferenceSliceSession())->demonstrateExpiredPreview($clock);

        self::assertStringContainsString('[preview]', $output);
        self::assertStringContainsString('agentools.precondition.confirmation_expired', $output);
    }

    /** Negative 3/3: edit issues a new prepare and invalidates the prior token (SUPERSEDED). */
    public function testItSupersedesTokenAfterEdit(): void
    {
        $output = (new ReferenceSliceSession())->demonstrateSupersededEdit();

        self::assertStringContainsString('SUPERSEDED:', $output);
        self::assertStringContainsString('superseded', strtolower($output));
    }

    /** P9.6 grep proof anchor: CLI layer must not embed gate policy classes. */
    public function testCliTreeDoesNotReferenceGatePolicy(): void
    {
        $cliDir = dirname(__DIR__, 3) . '/reference/Cli';
        $grep = shell_exec('rg -n GatePolicy ' . escapeshellarg($cliDir) . ' 2>/dev/null || true');
        self::assertSame('', $grep ?? '');
    }
}
