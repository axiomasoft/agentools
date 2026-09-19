<?php

declare(strict_types=1);

namespace AgenTools\Reference\Scenario;

use AgenTools\Confirmation\ConfirmationProtocol;
use AgenTools\Error\ToolExecutionResult;
use AgenTools\Reference\Adapters\DeliveryCollector;
use AgenTools\Reference\Adapters\RegistryToolPort;
use AgenTools\Reference\Cli\ConfirmationChoice;
use AgenTools\Reference\Crypto\TenantHmacTokenSigner;
use AgenTools\Reference\Support\InjectableClock;
use AgenTools\Reference\Support\JsonGuard;
use AgenTools\Reference\Tools\DayReportStore;
use AgenTools\Reference\Tools\ReferenceSliceToolFactory;
use AgenTools\Scenario\ClarificationSession;
use AgenTools\Schema\Canonicalizer;

/** End-of-day slice: read → draft → MRTR clarification → preview → single confirmation → write. */
final class DayEndOrchestrator
{
    public function __construct(
        private readonly DayReportStore $store,
        private readonly string $tenantId,
        private readonly InjectableClock $clock,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $script
     * @param callable(array<string, mixed>): ConfirmationChoice|null $resolveConfirmation
     *
     * @return array{final_state: string, metrics: ConfirmationMetrics, report_id: ?string}
     */
    public function run(
        array $script,
        string $vectorId,
        ?callable $resolveConfirmation = null,
        ?DeliveryCollector $delivery = null,
    ): array {
        $metrics = new ConfirmationMetrics();
        $delivery ??= new DeliveryCollector($this->tenantId);
        $port = new RegistryToolPort();
        (new ReferenceSliceToolFactory($this->store))->registerOn($port, $this->tenantId);
        $port->bindSubject($this->principals(), $this->authorization());

        $signer = new TenantHmacTokenSigner($this->tenantId, 'reference-slice-secret');
        $protocol = new ConfirmationProtocol($signer);
        $coordinator = new SliceToolCoordinator(
            $port,
            $protocol,
            $delivery,
            $metrics,
            new Canonicalizer(),
            $this->clock,
            'act-' . $vectorId,
            'user-a',
            new PermissionCommitAuthorizer(['slice.write']),
        );

        $clarification = new ClarificationSession();
        $reportId = null;
        $finalState = 'failed';
        /** @var array{tool: string, args: array<string, mixed>}|null $pendingWrite */
        $pendingWrite = null;

        foreach ($script as $step) {
            if (is_string($step['approval'] ?? null) && $pendingWrite !== null && $resolveConfirmation === null) {
                $result = $coordinator->invoke(
                    $pendingWrite['tool'],
                    $pendingWrite['args'],
                    $vectorId . ':' . $pendingWrite['tool'] . ':commit',
                    $step['approval'],
                );
                $pendingWrite = null;
                if ($result->isError()) {
                    return ['final_state' => 'failed', 'metrics' => $metrics, 'report_id' => null];
                }
                $report = is_array($result->value) ? ($result->value['report'] ?? null) : null;
                if (is_array($report)) {
                    $reportId = JsonGuard::string($report['report_id'] ?? null);
                    $finalState = 'completed';
                }
                continue;
            }
            if ($pendingWrite !== null && $resolveConfirmation !== null) {
                $outcome = $this->resolvePendingWrite(
                    $coordinator,
                    $pendingWrite,
                    $vectorId,
                    $resolveConfirmation,
                );
                $pendingWrite = $outcome['pending'];
                if ($outcome['failed']) {
                    return ['final_state' => 'failed', 'metrics' => $metrics, 'report_id' => null];
                }
                if ($outcome['rejected']) {
                    $finalState = 'rejected';
                }
                if ($outcome['report_id'] !== null) {
                    $reportId = $outcome['report_id'];
                    $finalState = 'completed';
                }
                continue;
            }
            if (isset($step['clarification']) && is_array($step['clarification'])) {
                $payload = JsonGuard::stringKeyedMap($step['clarification']);
                $clarification->request(
                    JsonGuard::string($payload['call_id'] ?? null, 'call-1'),
                    JsonGuard::string($payload['request_state'] ?? null, 'state-1'),
                    JsonGuard::stringKeyedMap(is_array($payload['requests'] ?? null) ? $payload['requests'] : []),
                );
                $clarification->answer(
                    JsonGuard::string($payload['answer_call_id'] ?? null, 'call-2'),
                    JsonGuard::stringKeyedMap(is_array($payload['answers'] ?? null) ? $payload['answers'] : []),
                );
                $delivery->deliver(['type' => 'clarification', 'rounds' => $clarification->rounds()]);
                continue;
            }

            $llmStub = is_array($step['llm_stub'] ?? null) ? JsonGuard::stringKeyedMap($step['llm_stub']) : [];
            $toolCall = is_array($llmStub['toolCall'] ?? null) ? JsonGuard::stringKeyedMap($llmStub['toolCall']) : null;
            if ($toolCall !== null) {
                $tool = JsonGuard::string($toolCall['tool'] ?? null);
                $args = JsonGuard::stringKeyedMap(is_array($toolCall['args'] ?? null) ? $toolCall['args'] : []);
                $result = $coordinator->invoke($tool, $args, $vectorId . ':' . $tool, null);
                if ($result->isError()) {
                    return ['final_state' => 'failed', 'metrics' => $metrics, 'report_id' => null];
                }
                if ($tool === 'slice_write_day_report' && ($result->value['awaiting_confirmation'] ?? false) === true) {
                    $pendingWrite = ['tool' => $tool, 'args' => $args];
                    if ($resolveConfirmation !== null) {
                        $outcome = $this->resolvePendingWrite(
                            $coordinator,
                            $pendingWrite,
                            $vectorId,
                            $resolveConfirmation,
                        );
                        $pendingWrite = $outcome['pending'];
                        if ($outcome['failed']) {
                            return ['final_state' => 'failed', 'metrics' => $metrics, 'report_id' => null];
                        }
                        if ($outcome['rejected']) {
                            $finalState = 'rejected';
                        }
                        if ($outcome['report_id'] !== null) {
                            $reportId = $outcome['report_id'];
                            $finalState = 'completed';
                        }
                    }
                }
            }
        }

        return ['final_state' => $finalState, 'metrics' => $metrics, 'report_id' => $reportId];
    }

    /**
     * @param array{tool: string, args: array<string, mixed>} $pendingWrite
     * @param callable(array<string, mixed>): ConfirmationChoice $resolveConfirmation
     *
     * @return array{pending: ?array{tool: string, args: array<string, mixed>}, failed: bool, rejected: bool, report_id: ?string}
     */
    private function resolvePendingWrite(
        SliceToolCoordinator $coordinator,
        array $pendingWrite,
        string $vectorId,
        callable $resolveConfirmation,
    ): array {
        while (true) {
            $choice = $resolveConfirmation($pendingWrite['args']);
            $args = $choice->arguments !== [] ? $choice->arguments : $pendingWrite['args'];
            $approval = match ($choice->decision) {
                'confirm', 'approve' => 'approve',
                default => $choice->decision,
            };
            $result = $coordinator->invoke(
                $pendingWrite['tool'],
                $args,
                $vectorId . ':' . $pendingWrite['tool'] . ':commit',
                $approval,
            );
            if ($result->isError()) {
                return ['pending' => null, 'failed' => true, 'rejected' => false, 'report_id' => null];
            }
            if (($result->value['confirmation_rejected'] ?? false) === true) {
                return ['pending' => null, 'failed' => false, 'rejected' => true, 'report_id' => null];
            }
            if (($result->value['awaiting_confirmation'] ?? false) === true) {
                $pendingWrite = ['tool' => $pendingWrite['tool'], 'args' => $args];

                continue;
            }
            $report = is_array($result->value) ? ($result->value['report'] ?? null) : null;
            if (is_array($report)) {
                return [
                    'pending' => null,
                    'failed' => false,
                    'rejected' => false,
                    'report_id' => JsonGuard::string($report['report_id'] ?? null),
                ];
            }

            return ['pending' => null, 'failed' => false, 'rejected' => false, 'report_id' => null];
        }
    }

    /** @return array<string, mixed> */
    private function principals(): array
    {
        return [
            'initiator' => ['id' => 'user-a'],
            'agent' => ['id' => 'agent-a'],
            'authorizer' => ['id' => 'user-a'],
        ];
    }

    /** @return array<string, mixed> */
    private function authorization(): array
    {
        return [
            'resource' => 'day-report',
            'tool' => 'slice_write_day_report',
            'permissions' => ['slice.read', 'slice.draft', 'slice.write'],
        ];
    }
}
