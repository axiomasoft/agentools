<?php

declare(strict_types=1);

namespace AgenTools\Ops;

use AgenTools\Action\ActionEvent;
use AgenTools\Action\Actor;
use AgenTools\Contracts\AuditSink;
use AgenTools\Contracts\Clock;
use AgenTools\Schema\PublishedSchemaValidator;

/** Builds audit.* journal events and optionally exports them (AOB-1..AOB-16). */
final class AuditEventFactory
{
    public function __construct(
        private readonly Clock $clock,
        private readonly ?PublishedSchemaValidator $validator = null,
        private readonly ?AuditSink $auditSink = null,
        private readonly string $specRevision = '2026-08',
    ) {
    }

    public function policyEvaluated(
        string $actionId,
        int $seq,
        int $expectedPrevSeq,
        Actor $actor,
        string $toolName,
        string $sideEffect,
        string $verdict,
    ): ActionEvent {
        return $this->build(
            $actionId,
            $seq,
            $expectedPrevSeq,
            $actor,
            'audit.policy_evaluated',
            [
                'tool_name' => $toolName,
                'side_effect' => $sideEffect,
                'verdict' => $verdict,
            ],
        );
    }

    /**
     * @param array<string, int|string> $costPayload
     */
    public function costRecorded(
        string $actionId,
        int $seq,
        int $expectedPrevSeq,
        Actor $actor,
        array $costPayload,
    ): ActionEvent {
        return $this->build($actionId, $seq, $expectedPrevSeq, $actor, 'audit.cost_recorded', $costPayload);
    }

    public function limitExceeded(
        string $actionId,
        int $seq,
        int $expectedPrevSeq,
        Actor $actor,
        string $limitName,
        int $limitValue,
        int $observed,
    ): ActionEvent {
        return $this->build(
            $actionId,
            $seq,
            $expectedPrevSeq,
            $actor,
            'audit.limit_exceeded',
            [
                'limit_name' => $limitName,
                'limit_value' => $limitValue,
                'observed' => $observed,
            ],
        );
    }

    /** @param array<string, mixed> $payload */
    private function build(
        string $actionId,
        int $seq,
        int $expectedPrevSeq,
        Actor $actor,
        string $kind,
        array $payload,
    ): ActionEvent {
        $event = new ActionEvent(
            eventId: sprintf('ev-%s-%04d', $actionId, $seq),
            actionId: $actionId,
            seq: $seq,
            expectedPrevSeq: $expectedPrevSeq,
            timestamp: $this->clock->now()->format(DATE_ATOM),
            actor: $actor,
            kind: $kind,
            eventVersion: 1,
            specRevision: $this->specRevision,
            payload: $payload,
        );
        $document = $event->toArray();
        $this->validator?->assertValid($document, 'https://agentools.dev/schema/2026-08/audit-event.json');
        $this->auditSink?->export($document);

        return $event;
    }
}
