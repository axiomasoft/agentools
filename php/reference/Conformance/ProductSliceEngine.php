<?php

declare(strict_types=1);

namespace AgenTools\Reference\Conformance;

use AgenTools\Reference\Scenario\DayEndOrchestrator;
use AgenTools\Reference\Support\InjectableClock;
use AgenTools\Reference\Support\JsonGuard;
use AgenTools\Reference\Tools\DayReportStore;
use RuntimeException;

/** Harness engine for fixtures/product-slice behavior vectors. */
final class ProductSliceEngine
{
    private string $clockIso = '2000-01-01T00:00:00Z';

    public function configure(mixed $clock, mixed $seed): void
    {
        if (!is_string($clock)) {
            throw new RuntimeException('fixed clock is required');
        }
        $this->clockIso = $clock;
    }

    /**
     * @param array<string, mixed> $vector
     * @param callable(array<string, mixed>):array<string, mixed> $probe
     * @param callable(array<string, mixed>):null $emit
     */
    public function run(string $vectorId, array $vector, callable $probe, callable $emit): void
    {
        $given = $vector['given'] ?? null;
        $script = $vector['script'] ?? null;
        if (!is_array($given) || !is_array($script)) {
            throw new RuntimeException('given and script are required');
        }

        $identity = is_array($given['identity'] ?? null) ? JsonGuard::stringKeyedMap($given['identity']) : [];
        $tenantId = JsonGuard::string($identity['tenant_id'] ?? null, 'tenant-a');
        $store = new DayReportStore();
        $seed = $given['product_slice_seed'] ?? null;
        if (is_array($seed)) {
            $seedMap = JsonGuard::stringKeyedMap($seed);
            $date = JsonGuard::string($seedMap['date'] ?? null, '2026-09-19');
            $store->seedActivities($tenantId, $date, JsonGuard::listOfMaps($seedMap['activities'] ?? null));
        }

        $this->event($emit, $vectorId, 'action_created');
        $this->event($emit, $vectorId, 'action_started');

        $orchestrator = new DayEndOrchestrator($store, $tenantId, new InjectableClock(new \DateTimeImmutable($this->clockIso)));
        $outcome = $orchestrator->run(JsonGuard::listOfMaps($script), $vectorId);

        $probe([
            'type' => 'probe_call',
            'vector_id' => $vectorId,
            'probe_id' => 'product_slice_confirmation',
            'probe_kind' => 'confirmation_boundary',
            'operation' => [
                'proposal_count' => $outcome['metrics']->proposalCount,
                'token_issue_count' => $outcome['metrics']->tokenIssueCount,
                'token_consume_count' => $outcome['metrics']->tokenConsumeCount,
                'decision_event_count' => $outcome['metrics']->decisionEventCount,
            ],
        ]);

        if ($outcome['final_state'] === 'completed') {
            $this->event($emit, $vectorId, 'confirmation_requested');
            $this->event($emit, $vectorId, 'confirmation_resolved', ['decision' => 'approve']);
            $this->event($emit, $vectorId, 'effect_requested', ['report_id' => $outcome['report_id']]);
            $this->event($emit, $vectorId, 'action_completed');
        }

        $this->result($emit, $vectorId, $outcome['final_state']);
    }

    /**
     * @param callable(array<string,mixed>):null $emit
     * @param array<string, mixed> $payload
     */
    private function event(callable $emit, string $id, string $kind, array $payload = []): void
    {
        $event = ['type' => 'event', 'vector_id' => $id, 'kind' => $kind];
        if ($payload !== []) {
            $event['payload'] = $payload;
        }
        $emit($event);
    }

    /** @param callable(array<string,mixed>):null $emit */
    private function result(callable $emit, string $id, string $state): void
    {
        $emit([
            'type' => 'result',
            'vector_id' => $id,
            'outcome' => 'completed',
            'final_state' => $state,
        ]);
    }
}
