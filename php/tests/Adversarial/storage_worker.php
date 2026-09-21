<?php

declare(strict_types=1);

namespace AgenTools\Tests\Adversarial;

use AgenTools\Action\Actor;
use AgenTools\Action\EventIdCollision;
use AgenTools\Action\JournalHashChain;
use AgenTools\Action\VersionConflict;
use AgenTools\Confirmation\ConfirmationFailure;
use AgenTools\Confirmation\PdoPostgresConfirmationTokenStore;
use AgenTools\Error\EventStoreFailure;
use AgenTools\Idempotency\ArgumentsFingerprint;
use AgenTools\Idempotency\DeduplicationPolicy;
use AgenTools\Idempotency\DeduplicationState;
use AgenTools\Idempotency\IdempotencyFailure;
use AgenTools\Idempotency\IdempotencyKey;
use AgenTools\Idempotency\IdempotencyLease;
use AgenTools\Idempotency\IdempotencyRequest;
use AgenTools\Idempotency\IdempotencyScope;
use AgenTools\Idempotency\PdoPostgresIdempotencyStore;
use AgenTools\Idempotency\StoredResultDigest;
use AgenTools\Idempotency\StoredToolResult;
use AgenTools\Persistence\EventStoreSupport;
use AgenTools\Persistence\PdoPostgresEventStore;
use AgenTools\Persistence\UncommittedEvent;
use AgenTools\Schema\Canonicalizer;
use PDO;
use PDOException;
use Throwable;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

final class StorageWorker
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
    private const UNFENCED_FINISH_SQL = <<<'SQL'
UPDATE agentools_idempotency_leases
SET state = 'completed', result_json = :result_json, result_digest = :result_digest,
    lease_token = NULL, lease_until = NULL
WHERE tenant_id = :tenant_id AND tool_name = :tool_name AND idempotency_key = :idempotency_key
  AND state = 'in_progress'
RETURNING tenant_id
SQL;

    private ?PDO $pdo = null;
    private string $mutant = '';
    private string $schema = '';
    private ?PdoPostgresConfirmationTokenStore $tokens = null;
    private ?PdoPostgresIdempotencyStore $leases = null;
    private ?PdoPostgresEventStore $events = null;
    private ?IdempotencyLease $lease = null;
    /** @var list<UncommittedEvent> */
    private array $preparedEvents = [];
    private int $preparedActual = 0;
    private string $preparedTenant = '';
    private string $preparedAction = '';
    private int $insertedInPrepare = 0;

    public function run(): void
    {
        $this->emit(['type' => 'ready', 'pid' => (int) (getmypid() ?: 0)]);
        while (($line = fgets(STDIN)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            /** @var array<string, mixed> $message */
            $message = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            $this->emit($this->handle($message));
            if (($message['op'] ?? '') === 'shutdown') {
                return;
            }
        }
    }

    /** @param array<string, mixed> $message */
    private function handle(array $message): array
    {
        try {
            $type = $message['type'] ?? '';
            if ($type === 'hello') {
                return $this->hello($message);
            }
            $op = (string) ($message['op'] ?? '');
            $id = (string) ($message['id'] ?? '');
            return match ($op) {
                'begin' => $this->ok($id, $this->begin()),
                'commit' => $this->ok($id, $this->commit()),
                'rollback' => $this->ok($id, $this->rollback()),
                'consume' => $this->ok($id, $this->consume($this->args($message))),
                'acquire' => $this->ok($id, $this->acquire($this->args($message))),
                'heartbeat' => $this->ok($id, $this->heartbeat($this->args($message))),
                'complete' => $this->ok($id, $this->complete($this->args($message))),
                'fail' => $this->ok($id, $this->fail($this->args($message))),
                'append' => $this->ok($id, $this->append($this->args($message))),
                'append_prepare' => $this->ok($id, $this->appendPrepare($this->args($message))),
                'append_one' => $this->ok($id, $this->appendOne($this->args($message))),
                'append_finish' => $this->ok($id, $this->appendFinish($this->args($message))),
                'load' => $this->ok($id, $this->loadPage($this->args($message))),
                'shutdown' => $this->ok($id, ['outcome' => 'shutdown']),
                default => ['id' => $id, 'status' => 'error', 'outcome' => 'unknown_op'],
            };
        } catch (Throwable $exception) {
            return $this->failure((string) ($message['id'] ?? ''), $exception);
        }
    }

    /** @param array<string, mixed> $message */
    private function hello(array $message): array
    {
        $this->mutant = (string) ($message['mutant'] ?? '');
        $this->schema = (string) $message['schema'];
        $dsn = (string) $message['dsn'];
        $user = (string) $message['user'];
        $password = (string) $message['password'];
        $application = (string) $message['application_name'];
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('SET application_name TO ' . $pdo->quote($application));
        $pdo->exec('SET search_path TO ' . $pdo->quote($this->schema));
        $pdo->exec("SET lock_timeout TO '" . (int) $message['lock_ms'] . "ms'");
        $pdo->exec("SET statement_timeout TO '" . (int) $message['statement_ms'] . "ms'");
        $pdo->exec("SET idle_in_transaction_session_timeout TO '" . (int) $message['idle_in_tx_ms'] . "ms'");
        $this->pdo = $pdo;
        $this->tokens = new PdoPostgresConfirmationTokenStore($pdo);
        $this->leases = new PdoPostgresIdempotencyStore(
            $pdo,
            new DeduplicationPolicy(new \DateInterval('P1D'), new \DateInterval('PT0S'), new \DateInterval('PT0S')),
        );
        $this->events = new PdoPostgresEventStore($pdo);
        $pidRow = $pdo->query('SELECT pg_backend_pid()');
        if ($pidRow === false) {
            throw new \RuntimeException('backend pid unavailable');
        }
        $pid = (int) $pidRow->fetchColumn();

        return ['status' => 'ok', 'backend_pid' => $pid, 'pid' => (int) (getmypid() ?: 0)];
    }

    /** @return array<string, mixed> */
    private function begin(): array
    {
        $this->pdo()->beginTransaction();
        return ['outcome' => 'began', 'backend_pid' => $this->backendPid()];
    }

    /** @return array<string, mixed> */
    private function commit(): array
    {
        if ($this->pdo()->inTransaction()) {
            $this->pdo()->commit();
        }
        return ['outcome' => 'committed'];
    }

    /** @return array<string, mixed> */
    private function rollback(): array
    {
        if ($this->pdo()->inTransaction()) {
            $this->pdo()->rollBack();
        }
        $this->preparedEvents = [];
        $this->insertedInPrepare = 0;
        return ['outcome' => 'rolled_back'];
    }

    /** @param array<string, mixed> $args */
    private function consume(array $args): array
    {
        $store = $this->tokens;
        if ($store === null) {
            throw new \RuntimeException('token store missing');
        }
        $store->consume(
            (string) $args['tenant_id'],
            (string) $args['token_id'],
            (string) $args['digest'],
            (string) $args['purpose'],
            new \DateTimeImmutable((string) $args['expires_at']),
            new \DateTimeImmutable((string) $args['now']),
        );

        return ['outcome' => 'accepted'];
    }

    /** @param array<string, mixed> $args */
    private function acquire(array $args): array
    {
        $store = $this->leases;
        if ($store === null) {
            throw new \RuntimeException('lease store missing');
        }
        $request = $this->request($args);
        $acquired = $store->acquire(
            $request,
            new \DateTimeImmutable((string) $args['now']),
            new \DateInterval('PT' . (int) $args['lease_seconds'] . 'S'),
        );
        if ($acquired->lease !== null) {
            $this->lease = $acquired->lease;
        }
        $outcome = $acquired->takeover
            ? 'takeover'
            : ($acquired->state === DeduplicationState::ConcurrentInFlight ? 'in_flight' : $acquired->state->value);

        return [
            'outcome' => $outcome,
            'attempt' => $acquired->lease?->attempt,
            'lease_token_sha256' => $acquired->lease === null ? null : hash('sha256', $acquired->lease->token),
        ];
    }

    /** @param array<string, mixed> $args */
    private function heartbeat(array $args): array
    {
        $store = $this->leases;
        $lease = $this->lease;
        if ($store === null || $lease === null) {
            throw IdempotencyFailure::lostLease();
        }
        $store->heartbeat($lease, new \DateTimeImmutable((string) $args['now']), new \DateInterval('PT30S'));
        return ['outcome' => 'heartbeat'];
    }

    /** @param array<string, mixed> $args */
    private function complete(array $args): array
    {
        return $this->finish($args, false);
    }

    /** @param array<string, mixed> $args */
    private function fail(array $args): array
    {
        return $this->finish($args, true);
    }

    /** @param array<string, mixed> $args */
    private function finish(array $args, bool $asFail): array
    {
        $lease = $this->lease;
        if ($lease === null) {
            throw IdempotencyFailure::lostLease();
        }
        $result = new StoredToolResult(value: ['ok' => true]);
        $digest = StoredResultDigest::fromJcs('{"ok":true}');
        if ($this->mutant === 'unfenced') {
            $encoded = \AgenTools\Idempotency\StoredToolResultCodec::encode($result, $digest);
            $statement = $this->pdo()->prepare(self::UNFENCED_FINISH_SQL);
            $statement->execute([
                'tenant_id' => $lease->request->scope->tenantId,
                'tool_name' => $lease->request->scope->toolName,
                'idempotency_key' => $lease->request->scope->key->value,
                'result_json' => $encoded['result_json'],
                'result_digest' => $encoded['digest'],
            ]);
            if ($statement->fetch() === false) {
                throw IdempotencyFailure::lostLease();
            }
            return ['outcome' => $asFail ? 'failed' : 'completed'];
        }
        $store = $this->leases;
        if ($store === null) {
            throw new \RuntimeException('lease store missing');
        }
        if ($asFail) {
            $store->fail($lease, $result, $digest, new \DateTimeImmutable((string) $args['now']));
        } else {
            $store->complete($lease, $result, $digest, new \DateTimeImmutable((string) $args['now']));
        }
        return ['outcome' => $asFail ? 'failed' : 'completed'];
    }

    /** @param array<string, mixed> $args */
    private function append(array $args): array
    {
        $store = $this->events;
        if ($store === null) {
            throw new \RuntimeException('event store missing');
        }
        $events = $this->eventsFrom($args);
        $result = $store->append(
            (string) $args['tenant_id'],
            (string) $args['action_id'],
            (int) $args['expected_prev_seq'],
            $events,
        );
        return ['outcome' => $result->outcome, 'first_seq' => $result->firstSeq, 'last_seq' => $result->lastSeq];
    }

    /** @param array<string, mixed> $args */
    private function appendPrepare(array $args): array
    {
        $events = $this->eventsFrom($args);
        $pdo = $this->pdo();
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }
        $tenant = (string) $args['tenant_id'];
        $action = (string) $args['action_id'];
        $lock = $pdo->prepare(self::LOCK_HEADER_SQL);
        $lock->execute(['tenant_id' => $tenant, 'action_id' => $action]);
        $header = $pdo->prepare(self::SELECT_HEADER_SQL);
        $header->execute(['tenant_id' => $tenant, 'action_id' => $action]);
        $actual = (int) $header->fetchColumn();
        $this->preparedEvents = $events;
        $this->preparedActual = $actual;
        $this->preparedTenant = $tenant;
        $this->preparedAction = $action;
        $this->insertedInPrepare = 0;
        return ['outcome' => 'locked', 'actual' => $actual, 'backend_pid' => $this->backendPid()];
    }

    /** @param array<string, mixed> $args */
    private function appendOne(array $args): array
    {
        $this->insertPrepared(1);
        return ['outcome' => 'inserted_one', 'count' => $this->insertedInPrepare];
    }

    /** @param array<string, mixed> $args */
    private function appendFinish(array $args): array
    {
        $this->insertPrepared(count($this->preparedEvents));
        if ($this->pdo()->inTransaction()) {
            $this->pdo()->commit();
        }
        return ['outcome' => 'appended', 'count' => $this->insertedInPrepare];
    }

    private function insertPrepared(int $want): void
    {
        $rules = new EventStoreSupport();
        $canonicalizer = new Canonicalizer();
        $chain = new JournalHashChain($canonicalizer);
        $pdo = $this->pdo();
        $insert = $pdo->prepare(self::INSERT_EVENT_SQL);
        while ($this->insertedInPrepare < $want) {
            $index = $this->insertedInPrepare;
            $event = $this->preparedEvents[$index];
            $seq = $this->preparedActual + $index + 1;
            $hash = $rules->contentHash($this->preparedTenant, $this->preparedAction, $event);
            $insert->execute([
                'tenant_id' => $this->preparedTenant,
                'action_id' => $this->preparedAction,
                'seq' => $seq,
                'event_id' => $event->eventId,
                'kind' => $event->kind,
                'event_version' => $event->eventVersion,
                'spec_rev' => $event->specRevision,
                'actor_type' => $event->actor->type,
                'actor_id' => $event->actor->id,
                'ts' => $event->timestamp,
                'payload_json' => json_encode($event->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'epoch' => $event->epoch,
                'prev_hash' => null,
                'content_hash' => $hash,
                'expected_prev_seq' => $this->preparedActual + $index,
            ]);
            $this->insertedInPrepare++;
            if ($this->mutant === 'non-atomic') {
                $pdo->commit();
                $pdo->beginTransaction();
            }
        }
        $update = $pdo->prepare(self::UPDATE_HEADER_SQL);
        $update->execute([
            'tenant_id' => $this->preparedTenant,
            'action_id' => $this->preparedAction,
            'current_seq' => $this->preparedActual + $this->insertedInPrepare,
        ]);
        unset($chain);
    }

    /** @param array<string, mixed> $args */
    private function loadPage(array $args): array
    {
        $store = $this->events;
        if ($store === null) {
            throw new \RuntimeException('event store missing');
        }
        $page = $store->load(
            (string) $args['tenant_id'],
            (string) $args['action_id'],
            (int) $args['after_seq'],
            (int) $args['limit'],
        );
        return ['outcome' => 'page', 'count' => count($page->events), 'has_more' => $page->hasMore, 'last_seq' => $page->lastSeq];
    }

    /** @param array<string, mixed> $args */
    private function request(array $args): IdempotencyRequest
    {
        return new IdempotencyRequest(
            new IdempotencyScope(
                (string) $args['tenant_id'],
                (string) $args['tool_name'],
                new IdempotencyKey((string) $args['key']),
            ),
            ArgumentsFingerprint::fromJcs((string) $args['fingerprint']),
            (string) $args['action_id'],
        );
    }

    /** @param array<string, mixed> $args @return list<UncommittedEvent> */
    private function eventsFrom(array $args): array
    {
        $events = [];
        /** @var list<array<string, mixed>> $raw */
        $raw = $args['events'];
        foreach ($raw as $item) {
            $events[] = new UncommittedEvent(
                (string) $item['event_id'],
                (string) $item['kind'],
                (int) $item['event_version'],
                (string) $item['spec_rev'],
                new Actor((string) $item['actor_type'], (string) $item['actor_id']),
                (string) $item['timestamp'],
                (array) $item['payload'],
            );
        }
        return $events;
    }

    /** @param array<string, mixed> $message @return array<string, mixed> */
    private function args(array $message): array
    {
        $args = $message['args'] ?? [];
        if (!is_array($args)) {
            throw new \InvalidArgumentException('args must be an object');
        }
        /** @var array<string, mixed> $args */
        return $args;
    }

    /** @param array<string, mixed> $payload */
    private function ok(string $id, array $payload): array
    {
        return ['id' => $id, 'status' => 'ok'] + $payload;
    }

    private function failure(string $id, Throwable $exception): array
    {
        $pdo = $this->pdo;
        $sqlstate = $exception instanceof PDOException ? (string) $exception->getCode() : null;
        if ($pdo instanceof PDO && $pdo->inTransaction() && !$this->isHeldOperation($exception)) {
            // Keep held transactions open; classification errors still roll store-owned txs.
        }
        $outcome = 'error';
        $code = null;
        if ($exception instanceof ConfirmationFailure) {
            $code = $exception->error->code;
            $outcome = str_contains($code, 'collision') ? 'collision' : 'spent';
        } elseif ($exception instanceof IdempotencyFailure) {
            $code = $exception->error->code;
            $outcome = str_contains($code, 'lost_lease') ? 'lost_lease' : (str_contains($code, 'in_flight') ? 'in_flight' : 'idempotency_error');
        } elseif ($exception instanceof VersionConflict) {
            $code = $exception->error->code;
            $outcome = 'version_conflict';
        } elseif ($exception instanceof EventIdCollision) {
            $code = $exception->error->code;
            $outcome = 'collision';
        } elseif ($exception instanceof EventStoreFailure) {
            $code = $exception->error->code;
            $outcome = str_contains($code, 'partial') ? 'partial_overlap' : 'event_error';
        } elseif ($sqlstate === '57014') {
            $outcome = 'query_cancel';
        } elseif (in_array($sqlstate, ['57P01', '57P02', '57P03'], true)) {
            $outcome = 'backend_terminate';
        }
        return [
            'id' => $id,
            'status' => 'error',
            'outcome' => $outcome,
            'sqlstate' => $sqlstate,
            'code' => $code,
            'fault' => in_array($outcome, ['query_cancel', 'backend_terminate'], true) ? $outcome : null,
        ];
    }

    private function isHeldOperation(Throwable $exception): bool
    {
        return $exception instanceof PDOException;
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            throw new \RuntimeException('not connected');
        }
        return $this->pdo;
    }

    private function backendPid(): int
    {
        $statement = $this->pdo()->query('SELECT pg_backend_pid()');
        if ($statement === false) {
            throw new \RuntimeException('backend pid unavailable');
        }
        return (int) $statement->fetchColumn();
    }

    /** @param array<string, mixed> $payload */
    private function emit(array $payload): void
    {
        fwrite(STDOUT, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
        fflush(STDOUT);
    }
}

(new StorageWorker())->run();
