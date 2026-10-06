<?php

declare(strict_types=1);

namespace AgenTools\Persistence;

use AgenTools\Action\ActionEvent;
use AgenTools\Action\Actor;
use AgenTools\Action\EventIdCollision;
use AgenTools\Action\JournalHashChain;
use AgenTools\Action\VersionConflict;
use AgenTools\Contracts\EventStore;
use AgenTools\Error\EventStoreFailure;
use AgenTools\Schema\Canonicalizer;
use PDO;

/** Durable EventStore. Classify under SELECT … FOR UPDATE; IDENTITY assigns global_position. */
final class PdoPostgresEventStore implements EventStore
{
    private const LOCK_HEADER_SQL = <<<'SQL'
INSERT INTO agentools_event_streams (tenant_id, action_id, current_seq)
VALUES (:tenant_id, :action_id, 0)
ON CONFLICT (tenant_id, action_id) DO NOTHING
SQL;

    private const SELECT_HEADER_SQL = <<<'SQL'
SELECT current_seq
FROM agentools_event_streams
WHERE tenant_id = :tenant_id AND action_id = :action_id
FOR UPDATE
SQL;

    private const LOOKUP_SQL = <<<'SQL'
SELECT event_id, action_id, seq, content_hash
FROM agentools_events
WHERE tenant_id = :tenant_id AND event_id = :event_id
SQL;

    private const INSERT_EVENT_SQL = <<<'SQL'
INSERT INTO agentools_events (
  tenant_id, action_id, seq, event_id, kind, event_version, spec_rev,
  actor_type, actor_id, ts, payload_json, epoch, prev_hash, content_hash, expected_prev_seq
) VALUES (
  :tenant_id, :action_id, :seq, :event_id, :kind, :event_version, :spec_rev,
  :actor_type, :actor_id, :ts, :payload_json, :epoch, :prev_hash, :content_hash, :expected_prev_seq
)
RETURNING seq, global_position
SQL;

    private const UPDATE_HEADER_SQL = <<<'SQL'
UPDATE agentools_event_streams
SET current_seq = :current_seq
WHERE tenant_id = :tenant_id AND action_id = :action_id
SQL;

    private const LOAD_SQL = <<<'SQL'
SELECT event_id, action_id, seq, expected_prev_seq, ts, actor_type, actor_id,
       kind, event_version, spec_rev, epoch, prev_hash, payload_json
FROM agentools_events
WHERE tenant_id = :tenant_id AND action_id = :action_id AND seq > :after_seq
ORDER BY seq ASC
LIMIT :limit
SQL;

    private const TAIL_HASH_SQL = <<<'SQL'
SELECT event_id, action_id, seq, expected_prev_seq, ts, actor_type, actor_id,
       kind, event_version, spec_rev, epoch, prev_hash, payload_json
FROM agentools_events
WHERE tenant_id = :tenant_id AND action_id = :action_id
ORDER BY seq DESC
LIMIT 1
SQL;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Canonicalizer $canonicalizer = new Canonicalizer(),
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /** @param list<UncommittedEvent> $events */
    public function append(string $tenantId, string $actionId, int $expectedPrevSeq, array $events): AppendResult
    {
        $rules = new EventStoreSupport($this->canonicalizer);
        $rules->assertAppendable($tenantId, $actionId, $events);
        $this->pdo->beginTransaction();
        try {
            $insertHeader = $this->pdo->prepare(self::LOCK_HEADER_SQL);
            $insertHeader->execute(['tenant_id' => $tenantId, 'action_id' => $actionId]);
            $header = $this->pdo->prepare(self::SELECT_HEADER_SQL);
            $header->execute(['tenant_id' => $tenantId, 'action_id' => $actionId]);
            $headerSeq = $header->fetchColumn();
            $actual = $headerSeq === false ? 0 : $this->intValue($headerSeq, 'current_seq');

            $hashes = [];
            foreach ($events as $event) {
                $hashes[] = $rules->contentHash($tenantId, $actionId, $event);
            }

            /** @var array<int, array<string, mixed>> $existing */
            $existing = [];
            $lookup = $this->pdo->prepare(self::LOOKUP_SQL);
            foreach ($events as $index => $event) {
                $lookup->execute(['tenant_id' => $tenantId, 'event_id' => $event->eventId]);
                $row = $lookup->fetch(PDO::FETCH_ASSOC);
                if (is_array($row)) {
                    $existing[$index] = $this->assoc($row);
                }
            }

            if ($existing === []) {
                $result = $this->insertAll($tenantId, $actionId, $actual, $expectedPrevSeq, $events, $hashes);
                $this->pdo->commit();

                return $result;
            }

            $classified = $this->classifyReplay($tenantId, $actionId, $expectedPrevSeq, $actual, $events, $hashes, $existing);
            $this->pdo->commit();

            return $classified;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function load(string $tenantId, string $actionId, int $afterSeq = 0, int $limit = EventStoreSupport::DEFAULT_PAGE): EventStreamPage
    {
        if ($tenantId === '' || $actionId === '') {
            throw EventStoreFailure::streamIdentity();
        }
        if ($limit < 1 || $limit > EventStoreSupport::MAX_PAGE) {
            throw EventStoreFailure::pageLimit($limit);
        }
        $statement = $this->pdo->prepare(self::LOAD_SQL);
        $statement->bindValue('tenant_id', $tenantId);
        $statement->bindValue('action_id', $actionId);
        $statement->bindValue('after_seq', $afterSeq, PDO::PARAM_INT);
        $statement->bindValue('limit', $limit + 1, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $hasMore = count($rows) > $limit;
        $page = array_slice($rows, 0, $limit);
        $events = [];
        foreach ($page as $row) {
            if (is_array($row)) {
                $events[] = $this->hydrate($this->assoc($row));
            }
        }
        $lastSeq = $events === [] ? $afterSeq : $events[count($events) - 1]->seq;

        return new EventStreamPage($events, $lastSeq, $hasMore);
    }

    /**
     * @param list<UncommittedEvent> $events
     * @param list<string> $hashes
     * @param array<int, array<string, mixed>> $existing
     */
    private function classifyReplay(
        string $tenantId,
        string $actionId,
        int $expectedPrevSeq,
        int $actual,
        array $events,
        array $hashes,
        array $existing,
    ): AppendResult {
        if (count($existing) !== count($events)) {
            foreach ($existing as $index => $row) {
                if ($this->stringField($row, 'content_hash') !== $hashes[$index]) {
                    throw new EventIdCollision($events[$index]->eventId);
                }
            }
            throw EventStoreFailure::partialOverlap();
        }
        foreach ($events as $index => $event) {
            $row = $existing[$index];
            if ($this->stringField($row, 'content_hash') !== $hashes[$index] || $this->stringField($row, 'action_id') !== $actionId) {
                throw new EventIdCollision($event->eventId);
            }
        }
        $firstSeq = $this->intField($existing[0], 'seq');
        foreach ($events as $index => $event) {
            if ($this->intField($existing[$index], 'seq') !== $firstSeq + $index) {
                throw EventStoreFailure::partialOverlap();
            }
        }
        if ($firstSeq !== $expectedPrevSeq + 1) {
            throw new VersionConflict($expectedPrevSeq, $actual);
        }
        $lastEventId = $events[0]->eventId;
        foreach ($events as $event) {
            $lastEventId = $event->eventId;
        }
        $position = $this->positionFor($tenantId, $lastEventId);

        return new AppendResult($firstSeq, $firstSeq + count($events) - 1, $position, AppendResult::IDEMPOTENT_REPLAY);
    }

    /**
     * @param list<UncommittedEvent> $events
     * @param list<string> $hashes
     */
    private function insertAll(
        string $tenantId,
        string $actionId,
        int $actual,
        int $expectedPrevSeq,
        array $events,
        array $hashes,
    ): AppendResult {
        if ($expectedPrevSeq !== $actual) {
            throw new VersionConflict($expectedPrevSeq, $actual);
        }
        $chain = new JournalHashChain($this->canonicalizer);
        $previous = $this->tailEvent($tenantId, $actionId);
        $insert = $this->pdo->prepare(self::INSERT_EVENT_SQL);
        $firstSeq = $actual + 1;
        $lastPosition = 0;
        foreach ($events as $index => $event) {
            $seq = $actual + $index + 1;
            $timestamp = $this->timestamp($event->timestamp);
            $committed = new ActionEvent(
                eventId: $event->eventId,
                actionId: $actionId,
                seq: $seq,
                expectedPrevSeq: $actual + $index,
                timestamp: $timestamp,
                actor: $event->actor,
                kind: $event->kind,
                eventVersion: $event->eventVersion,
                specRevision: $event->specRevision,
                payload: $event->payload,
                epoch: $event->epoch,
                previousHash: $previous === null ? null : $chain->hash($previous),
            );
            $insert->execute([
                'tenant_id' => $tenantId,
                'action_id' => $actionId,
                'seq' => $seq,
                'event_id' => $event->eventId,
                'kind' => $event->kind,
                'event_version' => $event->eventVersion,
                'spec_rev' => $event->specRevision,
                'actor_type' => $event->actor->type,
                'actor_id' => $event->actor->id,
                'ts' => $timestamp,
                'payload_json' => json_encode($event->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'epoch' => $event->epoch,
                'prev_hash' => $committed->previousHash,
                'content_hash' => $hashes[$index],
                'expected_prev_seq' => $actual + $index,
            ]);
            $returned = $insert->fetch(PDO::FETCH_ASSOC);
            if (!is_array($returned)) {
                throw new \RuntimeException('INSERT RETURNING produced no row.');
            }
            $lastPosition = $this->intField($this->assoc($returned), 'global_position');
            $previous = $committed;
        }
        $update = $this->pdo->prepare(self::UPDATE_HEADER_SQL);
        $update->execute([
            'tenant_id' => $tenantId,
            'action_id' => $actionId,
            'current_seq' => $actual + count($events),
        ]);

        return new AppendResult($firstSeq, $actual + count($events), $lastPosition, AppendResult::APPENDED);
    }

    private function tailEvent(string $tenantId, string $actionId): ?ActionEvent
    {
        $statement = $this->pdo->prepare(self::TAIL_HASH_SQL);
        $statement->execute(['tenant_id' => $tenantId, 'action_id' => $actionId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($this->assoc($row)) : null;
    }

    private function positionFor(string $tenantId, string $eventId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT global_position FROM agentools_events WHERE tenant_id = :tenant_id AND event_id = :event_id',
        );
        $statement->execute(['tenant_id' => $tenantId, 'event_id' => $eventId]);

        return $this->intValue($statement->fetchColumn(), 'global_position');
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ActionEvent
    {
        $decoded = json_decode($this->stringField($row, 'payload_json'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Stored event payload is not an object.');
        }
        $payload = $this->assoc($decoded);
        $epochRaw = $row['epoch'] ?? null;
        $prev = $row['prev_hash'] ?? null;

        return new ActionEvent(
            eventId: $this->stringField($row, 'event_id'),
            actionId: $this->stringField($row, 'action_id'),
            seq: $this->intField($row, 'seq'),
            expectedPrevSeq: $this->intField($row, 'expected_prev_seq'),
            timestamp: $this->timestamp($this->stringField($row, 'ts')),
            actor: new Actor($this->stringField($row, 'actor_type'), $this->stringField($row, 'actor_id')),
            kind: $this->stringField($row, 'kind'),
            eventVersion: $this->intField($row, 'event_version'),
            specRevision: $this->stringField($row, 'spec_rev'),
            payload: $payload,
            epoch: $epochRaw === null || $epochRaw === '' ? null : $this->intValue($epochRaw, 'epoch'),
            previousHash: is_string($prev) && $prev !== '' ? $prev : null,
        );
    }

    /**
     * Canonical lexical form of an event instant: UTC, microsecond precision (the TIMESTAMPTZ
     * resolution), fraction omitted when zero. The same form is stored, hashed into the chain
     * and hydrated, so `prev_hash` stays verifiable after reload regardless of the input offset,
     * input precision or the session time zone.
     */
    private function timestamp(string $value): string
    {
        $instant = (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));

        return $instant->format($instant->format('u') === '000000' ? 'Y-m-d\\TH:i:sP' : 'Y-m-d\\TH:i:s.uP');
    }

    /** @param array<mixed, mixed> $row
     *  @return array<string, mixed>
     */
    private function assoc(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            if (!is_string($key)) {
                throw new \RuntimeException('Event row keys must be strings.');
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /** @param array<string, mixed> $row */
    private function stringField(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new \RuntimeException(sprintf('Event row field %s must be a string.', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function intField(array $row, string $key): int
    {
        return $this->intValue($row[$key] ?? null, $key);
    }

    private function intValue(mixed $value, string $key): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        throw new \RuntimeException(sprintf('Event row field %s must be an integer.', $key));
    }
}
