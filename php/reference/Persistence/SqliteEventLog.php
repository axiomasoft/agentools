<?php

declare(strict_types=1);

namespace AgenTools\Reference\Persistence;

use AgenTools\Action\ActionEvent;
use AgenTools\Action\ActionReducer;
use AgenTools\Action\VersionConflict;
use AgenTools\Contracts\EventLog;
use PDO;

/** SQLite EventLog (single append, silent id no-op). Insufficient for P5.3 durable close:
 *  no tenant key, no IDENTITY global_position, no content-hash classifier, no keyset limit.
 */
final class SqliteEventLog implements EventLog
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ?ActionReducer $reducer = null,
    ) {
    }

    public function append(ActionEvent $event): void
    {
        $this->pdo->beginTransaction();
        try {
            $exists = $this->pdo->prepare('SELECT 1 FROM journal_events WHERE action_id = :action_id AND event_id = :event_id LIMIT 1');
            $exists->execute(['action_id' => $event->actionId, 'event_id' => $event->eventId]);
            if ($exists->fetchColumn() !== false) {
                $this->pdo->commit();
                return;
            }

            $lastSeq = $this->lastSeqWithinTransaction($event->actionId);
            if ($event->expectedPrevSeq !== $lastSeq) {
                throw new VersionConflict($event->expectedPrevSeq, $lastSeq);
            }

            $events = [...$this->readFromWithinTransaction($event->actionId, 1), $event];
            ($this->reducer ?? new ActionReducer())->fold($events);

            $row = ActionEventCodec::toRow($event);
            $insert = $this->pdo->prepare(
                'INSERT INTO journal_events (
                    event_id, action_id, seq, expected_prev_seq, ts, actor_type, actor_id,
                    kind, event_version, spec_rev, epoch, prev_hash, payload_json
                ) VALUES (
                    :event_id, :action_id, :seq, :expected_prev_seq, :ts, :actor_type, :actor_id,
                    :kind, :event_version, :spec_rev, :epoch, :prev_hash, :payload_json
                )',
            );
            $insert->execute($row);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function readFrom(string $actionId, int $fromSeq): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM journal_events WHERE action_id = :action_id AND seq >= :from_seq ORDER BY seq ASC',
        );
        $statement->execute(['action_id' => $actionId, 'from_seq' => $fromSeq]);
        $rows = $statement->fetchAll();
        $events = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $events[] = ActionEventCodec::fromRow($row);
        }

        return $events;
    }

    public function lastSeq(string $actionId): int
    {
        $statement = $this->pdo->prepare('SELECT MAX(seq) AS seq FROM journal_events WHERE action_id = :action_id');
        $statement->execute(['action_id' => $actionId]);
        $seq = $statement->fetchColumn();

        return is_numeric($seq) ? (int) $seq : 0;
    }

    private function lastSeqWithinTransaction(string $actionId): int
    {
        $statement = $this->pdo->prepare('SELECT MAX(seq) AS seq FROM journal_events WHERE action_id = :action_id');
        $statement->execute(['action_id' => $actionId]);
        $seq = $statement->fetchColumn();

        return is_numeric($seq) ? (int) $seq : 0;
    }

    /** @return list<ActionEvent> */
    private function readFromWithinTransaction(string $actionId, int $fromSeq): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM journal_events WHERE action_id = :action_id AND seq >= :from_seq ORDER BY seq ASC',
        );
        $statement->execute(['action_id' => $actionId, 'from_seq' => $fromSeq]);
        $rows = $statement->fetchAll();
        $events = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $events[] = ActionEventCodec::fromRow($row);
        }

        return $events;
    }
}
