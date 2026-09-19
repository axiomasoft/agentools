<?php

declare(strict_types=1);

namespace AgenTools\Reference\Cli;

use AgenTools\Reference\Adapters\DeliveryCollector;
use AgenTools\Reference\Scenario\DayEndOrchestrator;
use AgenTools\Reference\Support\InjectableClock;
use AgenTools\Reference\Support\JsonGuard;
use AgenTools\Reference\Tools\DayReportStore;

/** Text-mode CLI session over DeliveryCollector deliveries (INT-002 §6). */
final class ReferenceSliceSession
{
    public function __construct(
        private readonly DeliveryRenderer $renderer = new DeliveryRenderer(),
        private readonly SliceToolCoordinatorFactory $coordinatorFactory = new SliceToolCoordinatorFactory(),
    ) {
    }

    /**
     * @param list<array<string, mixed>> $script
     * @param list<ConfirmationChoice> $confirmationScript
     *
     * @return array{output: string, outcome: array{final_state: string, metrics: \AgenTools\Reference\Scenario\ConfirmationMetrics, report_id: ?string}}
     */
    public function run(
        array $script,
        string $vectorId,
        string $tenantId,
        InjectableClock $clock,
        DayReportStore $store,
        array $confirmationScript = [],
    ): array {
        $collector = new DeliveryCollector($tenantId);
        $consumer = new DeliveryConsumer($this->renderer, $tenantId);
        $driver = new InteractiveConfirmationDriver();
        $driver->loadScript($confirmationScript);

        $orchestrator = new DayEndOrchestrator($store, $tenantId, $clock);
        $output = $consumer->drain($collector);
        $outcome = $orchestrator->run(
            $script,
            $vectorId,
            static function (array $pendingArgs) use ($driver): ConfirmationChoice {
                return $driver->nextChoice($pendingArgs);
            },
            $collector,
        );

        $output .= $consumer->drain($collector);

        return ['output' => $output, 'outcome' => $outcome];
    }

    public function demonstrateWrongTenant(): string
    {
        $foreignCollector = new DeliveryCollector('tenant-b');
        $foreignCollector->deliver([
            'type' => 'preview',
            'tool' => 'slice_write_day_report',
            'preview' => ['summary' => 'Foreign', 'changes' => []],
        ]);
        $consumer = new DeliveryConsumer($this->renderer, 'tenant-a');

        return $consumer->drain($foreignCollector);
    }

    public function demonstrateExpiredPreview(InjectableClock $clock): string
    {
        $bundle = $this->coordinatorFactory->create('tenant-a', $clock, 'cli-ttl');
        $args = ['report_id' => 'r-ttl', 'date' => '2026-09-19', 'body' => 'body'];
        $bundle->coordinator->invoke('slice_write_day_report', $args, 'idem-ttl', null);
        $consumer = new DeliveryConsumer($this->renderer, 'tenant-a');
        $output = $consumer->drain($bundle->collector);
        $clock->advance('PT3H');
        $result = $bundle->coordinator->invoke('slice_write_day_report', $args, 'idem-ttl-commit', 'approve');
        $code = $result->isError() && $result->error !== null ? $result->error->code : 'ok';

        return $output . sprintf("TTL: confirmation error=%s\n", $code);
    }

    public function demonstrateSupersededEdit(): string
    {
        $bundle = $this->coordinatorFactory->create(
            'tenant-a',
            new InjectableClock(new \DateTimeImmutable('2026-09-19T10:00:00+00:00')),
            'cli-edit',
        );
        $args = ['report_id' => 'r-edit', 'date' => '2026-09-19', 'body' => 'original'];
        $bundle->coordinator->invoke('slice_write_day_report', $args, 'idem-edit', null);
        $oldToken = (string) $bundle->coordinator->pendingTokenValue();
        $consumer = new DeliveryConsumer($this->renderer, 'tenant-a');
        $output = $consumer->drain($bundle->collector);
        $edited = ['report_id' => 'r-edit', 'date' => '2026-09-19', 'body' => 'edited'];
        $bundle->coordinator->invoke('slice_write_day_report', $edited, 'idem-edit', 'edit');
        $output .= $consumer->drain($bundle->collector);
        $superseded = $bundle->coordinator->rejectSupersededToken($oldToken);
        $title = $superseded->isError() && $superseded->error !== null ? $superseded->error->title : '';

        return $output . sprintf("SUPERSEDED: %s\n", $title);
    }

    /**
     * @return array{given: array<string, mixed>, script: list<array<string, mixed>>}
     */
    public function loadProductVector(string $path): array
    {
        /** @var array<string, mixed> $vector */
        $vector = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $given = is_array($vector['given'] ?? null) ? JsonGuard::stringKeyedMap($vector['given']) : [];
        $script = JsonGuard::listOfMaps($vector['script'] ?? null);

        return ['given' => $given, 'script' => $script];
    }
}
