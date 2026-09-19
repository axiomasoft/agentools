<?php

declare(strict_types=1);

namespace AgenTools\Reference\Conformance;

use AgenTools\Action\ActionEvent;
use AgenTools\Action\Actor;
use AgenTools\Action\VersionConflict;
use AgenTools\Reference\Persistence\ReferenceSliceDatabase;
use AgenTools\Reference\Persistence\SqliteEventLog;

/** Replays journal vectors through SQLite EventLog before harness execution. */
final class SqliteJournalExercise
{
    public function __construct(private readonly ReferenceSliceDatabase $database)
    {
    }

    /** @param array<string, mixed> $vector */
    public function exercise(array $vector): void
    {
        $given = $vector['given'] ?? null;
        if (!is_array($given)) {
            return;
        }
        $state = $given['driver_state'] ?? null;
        if (!is_array($state) || !is_array($state['journal'] ?? null)) {
            return;
        }

        $log = new SqliteEventLog($this->database->pdo());
        foreach ($state['journal'] as $event) {
            if (!is_array($event)) {
                continue;
            }
            /** @var array<string, mixed> $event */
            try {
                $log->append($this->toActionEvent($event));
            } catch (VersionConflict) {
                return;
            }
        }

        $probe = $state['append_probe'] ?? null;
        if (!is_array($probe) || !is_array($probe['attempted_event'] ?? null)) {
            return;
        }
        /** @var array<string, mixed> $attempt */
        $attempt = $probe['attempted_event'];
        $storageLastSeq = $probe['storage_last_seq'] ?? null;
        if (!is_int($storageLastSeq)) {
            return;
        }

        $actionId = isset($attempt['action_id']) && is_string($attempt['action_id']) ? $attempt['action_id'] : 'act-unknown';
        if ($log->lastSeq($actionId) !== $storageLastSeq) {
            return;
        }

        try {
            $log->append($this->toActionEvent($attempt));
        } catch (VersionConflict) {
            return;
        }
    }

    /** @param array<string, mixed> $event */
    private function toActionEvent(array $event): ActionEvent
    {
        $actor = is_array($event['actor'] ?? null) ? $event['actor'] : [];

        return new ActionEvent(
            eventId: isset($event['event_id']) && is_string($event['event_id']) ? $event['event_id'] : 'ev-unknown',
            actionId: isset($event['action_id']) && is_string($event['action_id']) ? $event['action_id'] : 'act-unknown',
            seq: isset($event['seq']) && is_int($event['seq']) ? $event['seq'] : 0,
            expectedPrevSeq: isset($event['expected_prev_seq']) && is_int($event['expected_prev_seq']) ? $event['expected_prev_seq'] : 0,
            timestamp: isset($event['ts']) && is_string($event['ts']) ? $event['ts'] : '2000-01-01T00:00:00Z',
            actor: new Actor(
                isset($actor['type']) && is_string($actor['type']) ? $actor['type'] : 'system',
                isset($actor['id']) && is_string($actor['id']) ? $actor['id'] : 'runtime',
            ),
            kind: isset($event['kind']) && is_string($event['kind']) ? $event['kind'] : 'unknown',
            eventVersion: isset($event['event_version']) && is_int($event['event_version']) ? $event['event_version'] : 1,
            specRevision: isset($event['spec_rev']) && is_string($event['spec_rev']) ? $event['spec_rev'] : '2026-08',
            payload: $this->payload($event),
            epoch: isset($event['epoch']) && is_int($event['epoch']) ? $event['epoch'] : null,
            previousHash: isset($event['prev_hash']) && is_string($event['prev_hash']) ? $event['prev_hash'] : null,
        );
    }

    /**
     * @param array<string, mixed> $event
     *
     * @return array<string, mixed>
     */
    private function payload(array $event): array
    {
        $payload = $event['payload'] ?? [];
        if (!is_array($payload)) {
            return [];
        }

        $normalized = [];
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}
