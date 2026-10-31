<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Outbox;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, RunId};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Runtime\{Connection, RepositoryContext, StorageFailure};

/** Only repeatable wake transport is relayed here. Owner/provider effects are separate intents. */
final readonly class OutboxRepository
{
    public function __construct(private RepositoryContext $environment, private int $leaseMilliseconds = 30000,
        private int $backoffMilliseconds = 1000)
    {
        if ($leaseMilliseconds < 1 || $leaseMilliseconds > 3600000
            || $backoffMilliseconds < 1 || $backoffMilliseconds > 3600000) {
            throw new \InvalidArgumentException('Bounded relay policy required');
        }
    }

    public function claim(ExecutionContext $context): ?OutboxClaim
    {
        $this->environment->authorize($context);
        $db = $this->environment->db;
        $owner = bin2hex(random_bytes(32));
        return $db->transaction(function () use ($db, $context, $owner): ?OutboxClaim {
            $row = $db->row('SELECT o.* FROM ' . $db->table('outbox') . ' o
                JOIN ' . $db->table('runs') . ' r ON r.tenant_id=o.tenant_id AND r.id=o.aggregate_ref
                JOIN ' . $db->table('turns') . ' t ON t.tenant_id=r.tenant_id AND t.id=r.turn_id
                WHERE o.tenant_id=:tenant AND t.session_id=:session AND o.event_type=\'run.accepted\'
                AND o.status IN (\'pending\',\'unknown\') AND o.available_at <= clock_timestamp()
                AND o.deadline_at > clock_timestamp() AND o.attempt_count < o.max_attempts
                AND (o.claimed_until IS NULL OR o.claimed_until <= clock_timestamp())
                ORDER BY o.available_at,o.id LIMIT 1 FOR UPDATE OF o SKIP LOCKED', [
                    'tenant' => $context->session->tenant->value, 'session' => $context->session->value]);
            if ($row === null) {
                return null;
            }
            $updated = $db->row('UPDATE ' . $db->table('outbox') . ' SET version=version+1,
                fencing_epoch=fencing_epoch+1,attempt_count=attempt_count+1,claim_owner=:owner,
                claimed_until=clock_timestamp() + CAST(:lease AS integer) * interval \'1 millisecond\'
                WHERE tenant_id=:tenant AND id=:id AND version=:version RETURNING *', [
                    'owner' => $owner, 'lease' => $this->leaseMilliseconds,
                    'tenant' => $context->session->tenant->value, 'id' => Connection::text($row, 'id'),
                    'version' => Connection::number($row, 'version')]) ?? throw new StorageFailure();
            return new OutboxClaim(Connection::text($updated, 'id'), new WakeSignal(
                new RunId($context->session->tenant, Connection::text($updated, 'aggregate_ref')),
                Connection::text($updated, 'event_key'), Connection::text($updated, 'payload_hash')),
                Connection::number($updated, 'version'), Connection::number($updated, 'fencing_epoch'), $owner,
                Connection::number($updated, 'attempt_count'), Connection::text($updated, 'claimed_until'));
        });
    }

    public function dispatched(ExecutionContext $context, OutboxClaim $claim): void
    {
        $this->finish($context, $claim, true);
    }

    /** An unacknowledged publish stays unknown; retrying the same wake key is safe. */
    public function unknown(ExecutionContext $context, OutboxClaim $claim): void
    {
        $this->finish($context, $claim, false);
    }

    /** Bounded scanner also handles crash on the last attempt, retaining the unresolved row. */
    public function deadletter(ExecutionContext $context, int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Bounded scan required');
        }
        $this->environment->authorize($context);
        $db = $this->environment->db;
        return $db->transaction(function () use ($db, $context, $limit): int {
            return $db->execute('WITH exhausted AS (SELECT o.tenant_id,o.id FROM ' . $db->table('outbox') . ' o
                JOIN ' . $db->table('runs') . ' r ON r.tenant_id=o.tenant_id AND r.id=o.aggregate_ref
                JOIN ' . $db->table('turns') . ' t ON t.tenant_id=r.tenant_id AND t.id=r.turn_id
                WHERE o.tenant_id=:tenant AND t.session_id=:session AND o.event_type=\'run.accepted\'
                AND o.status IN (\'pending\',\'unknown\')
                AND (o.claimed_until IS NULL OR o.claimed_until <= clock_timestamp())
                AND (o.attempt_count >= o.max_attempts OR o.deadline_at <= clock_timestamp())
                ORDER BY o.created_at,o.id LIMIT ' . $limit . ' FOR UPDATE OF o SKIP LOCKED)
                UPDATE ' . $db->table('outbox') . ' o SET version=version+1,status=\'deadletter\',claim_owner=NULL,claimed_until=NULL
                FROM exhausted e WHERE e.tenant_id=o.tenant_id AND e.id=o.id', [
                    'tenant' => $context->session->tenant->value, 'session' => $context->session->value])->rowCount();
        });
    }

    private function finish(ExecutionContext $context, OutboxClaim $claim, bool $dispatched): void
    {
        $context->session->tenant->assertSame($claim->signal->run->tenant);
        $this->environment->authorize($context);
        $db = $this->environment->db;
        // Saturating backoff; no worker wall clock enters SQL scheduling.
        $backoff = min(3600000, $this->backoffMilliseconds * (2 ** min(20, max(0, $claim->attempt - 1))));
        $db->transaction(function () use ($db, $context, $claim, $dispatched, $backoff): void {
            $statement = $db->execute('UPDATE ' . $db->table('outbox') . ' o SET version=version+1,
                status=CASE WHEN :sent=1 THEN \'dispatched\'
                    WHEN attempt_count >= max_attempts OR deadline_at <= clock_timestamp() THEN \'deadletter\' ELSE \'unknown\' END,
                dispatched_at=CASE WHEN :receipt=1 THEN clock_timestamp() ELSE NULL END,
                available_at=clock_timestamp() + CAST(:backoff AS integer) * interval \'1 millisecond\',
                claim_owner=NULL,claimed_until=NULL
                WHERE o.tenant_id=:tenant AND o.id=:id AND o.version=:version AND o.fencing_epoch=:epoch
                AND o.claim_owner=:owner AND o.claimed_until > clock_timestamp()
                AND o.status IN (\'pending\',\'unknown\') AND o.event_type=\'run.accepted\'
                AND EXISTS (SELECT 1 FROM ' . $db->table('runs') . ' r JOIN ' . $db->table('turns') . ' t
                    ON t.tenant_id=r.tenant_id AND t.id=r.turn_id
                    WHERE r.tenant_id=o.tenant_id AND r.id=o.aggregate_ref AND t.session_id=:session)', [
                    'sent' => $dispatched ? 1 : 0, 'receipt' => $dispatched ? 1 : 0, 'backoff' => $backoff,
                    'tenant' => $context->session->tenant->value, 'id' => $claim->id,
                    'version' => $claim->version, 'epoch' => $claim->epoch, 'owner' => $claim->owner,
                    'session' => $context->session->value]);
            if ($statement->rowCount() !== 1) {
                throw new ContractViolation(ErrorCode::StaleClaim);
            }
        });
    }
}
