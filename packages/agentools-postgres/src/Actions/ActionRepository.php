<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Actions;

use AgenTools\Action\{ActionReducer, FoldedAction, JournalHashChain};
use AgenTools\Persistence\{EventStoreSupport, PdoPostgresEventStore, UncommittedEvent};
use AgenTools\Schema\Canonicalizer;
use AgenToolsPlatform\Core\Common\{ActionId, ContractViolation, ErrorCode, IntegrityConflict, PayloadHash, Ref};
use AgenToolsPlatform\Core\Context\ContextSnapshot;
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Claims\{ClaimPolicy, RunClaim};
use AgenToolsPlatform\Postgres\Runtime\{Connection, RepositoryContext, StorageFailure};

/** The dated EventStore participates in the same PDO transaction as all platform writes. */
final readonly class ActionRepository implements TransactionBoundary
{
    private PdoPostgresEventStore $journal;

    public function __construct(private RepositoryContext $environment, private ClaimPolicy $policy = new ClaimPolicy())
    {
        $this->journal = $environment->db->journal();
    }

    /**
     * @param list<UncommittedEvent> $events
     * @param list<EffectIntent> $intents
     */
    public function append(ExecutionContext $context, ContextSnapshot $snapshot, RunClaim $claim,
        ActionId $action, int $expectedPrevSeq, array $events, array $intents = []): Projection
    {
        $this->authorize($context, $snapshot, $claim, $action);
        $byKey = [];
        foreach ($intents as $intent) {
            $action->tenant->assertSame($intent->requestRef->tenant);
            if (isset($byKey[$intent->effectKey])) {
                throw new IntegrityConflict('Duplicate effect identity');
            }
            $byKey[$intent->effectKey] = $intent;
        }
        $db = $this->environment->db;
        return $db->transaction(function () use ($db, $context, $snapshot, $claim, $action, $expectedPrevSeq, $events, $byKey): Projection {
            $this->lockClaim($context, $claim, $action);
            $result = $this->journal->appendInTransaction($action->tenant->value, $action->value, $expectedPrevSeq, $events);
            $projection = $this->fold($action);
            $requested = [];
            foreach ($events as $event) {
                if ($event->kind === 'epoch_started' && $event->epoch !== $claim->state->fencingEpoch) {
                    throw new ContractViolation(ErrorCode::StaleClaim);
                }
                if ($event->kind === 'effect_requested') {
                    $key = $event->payload['effect_id'] ?? null;
                    if (!is_string($key) || !isset($byKey[$key])) {
                        throw new ContractViolation(ErrorCode::InvalidInput);
                    }
                    $intent = $byKey[$key];
                    if ($event->epoch !== $claim->state->fencingEpoch
                        || ($event->payload['idempotency_key'] ?? null) !== $intent->ownerOperationKey
                        || ($event->payload['request_hash'] ?? null) !== substr($intent->requestHash->value, 8)
                        || ($event->payload['request_ref'] ?? null) !== $intent->requestRef->value
                        || ($event->payload['owner_namespace'] ?? null) !== $intent->ownerNamespace) {
                        throw new IntegrityConflict('Effect request binding conflict');
                    }
                    $requested[] = $key;
                    $this->persistIntent($context, $snapshot, $claim, $action, $event, $intent, $result->appended());
                }
                if ($result->appended()) {
                    $hash = (new EventStoreSupport())->contentHash($action->tenant->value, $action->value, $event);
                    $db->execute('INSERT INTO ' . $db->table('outbox') . '
                        (tenant_id,id,event_key,payload_hash,event_type,aggregate_ref,deadline_at,retention_until)
                        VALUES (:tenant,:id,:event,:hash,\'action.journaled\',:action,
                            clock_timestamp()+interval \'1 day\',clock_timestamp()+interval \'8 days\')', [
                            'tenant' => $action->tenant->value, 'id' => 'action.event:' . $event->eventId,
                            'event' => 'action.event:' . $event->eventId, 'hash' => substr($hash, 8), 'action' => $action->value]);
                    if (in_array($event->kind, ['effect_confirmed','effect_failed'], true)) {
                        $updated = $db->execute('UPDATE ' . $db->table('effect_intents') . ' SET version=version+1,outcome=:outcome,receipt_ref=:receipt
                            WHERE tenant_id=:tenant AND action_ref=:action AND effect_key=:key AND outcome IN (\'pending\',\'unknown\')', [
                                'outcome' => $event->kind === 'effect_confirmed' ? 'success' : 'failed',
                                'receipt' => is_string($event->payload['external_ref'] ?? null) ? $event->payload['external_ref'] : null,
                                'tenant' => $action->tenant->value, 'action' => $action->value,
                                'key' => is_string($event->payload['effect_id'] ?? null) ? $event->payload['effect_id'] : '']);
                        if ($updated->rowCount() !== 1) {
                            throw new IntegrityConflict('Effect outcome conflicts with durable intent');
                        }
                    }
                }
            }
            if (count($requested) !== count($byKey)) {
                throw new ContractViolation(ErrorCode::InvalidInput);
            }
            if ($result->appended()) {
                $this->writeProjection($action, $projection);
            } else {
                $saved = $db->row('SELECT digest FROM ' . $db->table('action_current') . ' WHERE tenant_id=:tenant AND id=:id',
                    ['tenant' => $action->tenant->value, 'id' => $action->value]);
                if ($saved === null || Connection::text($saved, 'digest') !== $projection->digest()) {
                    throw new IntegrityConflict('Projection needs verified rebuild');
                }
            }
            $this->lockClaim($context, $claim, $action);
            return $projection;
        });
    }

    /** No owner call here. A fresh committed lookup supplies the immutable request to owner composition. */
    public function committedIntent(ExecutionContext $context, ContextSnapshot $snapshot, RunClaim $claim,
        ActionId $action, string $effectKey): CommittedIntent
    {
        $this->authorize($context, $snapshot, $claim, $action);
        $db = $this->environment->db;
        return $db->transaction(function () use ($db, $context, $claim, $action, $effectKey): CommittedIntent {
            $this->lockClaim($context, $claim, $action);
            $state = $this->fold($action)->state;
            $effect = $state->effects[$effectKey] ?? null;
            if ($effect === null || $effect->status !== \AgenTools\Action\EffectStatus::Requested
                || $state->cancellationRequested || $effect->epoch !== $claim->state->fencingEpoch) {
                throw new ContractViolation(ErrorCode::ManualResolutionRequired);
            }
            $row = $db->row('SELECT * FROM ' . $db->table('effect_intents') . '
                WHERE tenant_id=:tenant AND action_ref=:action AND effect_key=:key AND outcome=\'pending\' AND claim_epoch=:epoch', [
                    'tenant' => $action->tenant->value, 'action' => $action->value, 'key' => $effectKey,
                    'epoch' => $claim->state->fencingEpoch]);
            if ($row === null) {
                throw new ContractViolation(ErrorCode::ManualResolutionRequired);
            }
            $authorization = $this->named(Connection::text($row, 'request_context'));
            if (($authorization['scope_hash'] ?? null) !== $context->scopeHash($this->environment->hasher)->value
                || Connection::text($row, 'owner_operation_key') !== $effect->idempotencyKey) {
                throw new ContractViolation(ErrorCode::StaleContext);
            }
            $this->lockClaim($context, $claim, $action);
            return new CommittedIntent($action, new EffectIntent($effectKey, Connection::text($row, 'owner_namespace'),
                Connection::text($row, 'owner_operation_key'), new PayloadHash('sha-256:' . Connection::text($row, 'request_hash')),
                new Ref($action->tenant, Connection::text($row, 'request_ref'))), Connection::number($row, 'claim_epoch'), $authorization);
        });
    }

    /** Fresh staging projection, digest comparison and swap; the journal is never rewritten. */
    public function rebuild(ExecutionContext $context, ActionId $action, string $expectedDigest): Projection
    {
        $action->tenant->assertSame($context->session->tenant);
        $this->environment->authorize($context);
        $db = $this->environment->db;
        return $db->transaction(function () use ($db, $context, $action, $expectedDigest): Projection {
            $this->binding($context, $action);
            $head = $db->row('SELECT current_seq FROM ' . $db->table('agentools_event_streams') . '
                WHERE tenant_id=:tenant AND action_id=:action FOR UPDATE', ['tenant' => $action->tenant->value, 'action' => $action->value]);
            if ($head === null) {
                throw new ContractViolation(ErrorCode::AccessDenied);
            }
            $projection = $this->fold($action);
            if (!hash_equals($expectedDigest, $projection->digest())) {
                throw new IntegrityConflict('Rebuild canonical digest conflict');
            }
            $parameters = ['tenant' => $action->tenant->value, 'id' => $action->value];
            $db->execute('INSERT INTO ' . $db->table('action_projection_rebuilds') . '(tenant_id,id,action_id,version,state,digest)
                VALUES (:tenant,:id,:action,:version,CAST(:state AS jsonb),:digest)', [
                    'tenant' => $action->tenant->value, 'id' => bin2hex(random_bytes(32)), 'action' => $action->value,
                    'version' => $projection->state->seq, 'state' => $projection->json(), 'digest' => $projection->digest()]);
            $db->execute('DELETE FROM ' . $db->table('action_current') . ' WHERE tenant_id=:tenant AND id=:id', $parameters);
            $this->writeProjection($action, $projection);
            return $projection;
        });
    }

    private function authorize(ExecutionContext $context, ContextSnapshot $snapshot, RunClaim $claim, ActionId $action): void
    {
        $action->tenant->assertSame($context->session->tenant);
        $action->tenant->assertSame($claim->state->id->tenant);
        $this->environment->authorize($context, $snapshot);
    }

    private function binding(ExecutionContext $context, ActionId $action): string
    {
        $db = $this->environment->db;
        $row = $db->row('SELECT p.run_id FROM ' . $db->table('proposal_map') . ' p
            JOIN ' . $db->table('runs') . ' r ON r.tenant_id=p.tenant_id AND r.id=p.run_id
            JOIN ' . $db->table('turns') . ' t ON t.tenant_id=r.tenant_id AND t.id=r.turn_id
            WHERE p.tenant_id=:tenant AND p.action_ref=:action AND t.session_id=:session', [
                'tenant' => $action->tenant->value, 'action' => $action->value, 'session' => $context->session->value]);
        return $row === null ? throw new ContractViolation(ErrorCode::AccessDenied) : Connection::text($row, 'run_id');
    }

    private function lockClaim(ExecutionContext $context, RunClaim $claim, ActionId $action): void
    {
        if ($this->binding($context, $action) !== $claim->state->id->value) {
            throw new ContractViolation(ErrorCode::AccessDenied);
        }
        $db = $this->environment->db;
        $row = $db->row('SELECT id FROM ' . $db->table('runs') . ' WHERE tenant_id=:tenant AND id=:id
            AND version=:version AND fencing_epoch=:epoch AND claim_owner=:owner AND lease_until>clock_timestamp()
            AND created_at+make_interval(secs => CAST(:deadline AS integer))>clock_timestamp()
            AND status NOT IN (\'completed\',\'failed\',\'cancelled\') FOR UPDATE', [
                'tenant' => $action->tenant->value, 'id' => $claim->state->id->value, 'version' => $claim->state->version,
                'epoch' => $claim->state->fencingEpoch, 'owner' => $claim->owner, 'deadline' => $this->policy->deadlineSeconds]);
        if ($row === null) {
            throw new ContractViolation(ErrorCode::StaleClaim);
        }
    }

    private function persistIntent(ExecutionContext $context, ContextSnapshot $snapshot, RunClaim $claim, ActionId $action,
        UncommittedEvent $event, EffectIntent $intent, bool $insert): void
    {
        $db = $this->environment->db;
        $authorization = ['execution_context' => $context->toArray(),
            'scope_hash' => $context->scopeHash($this->environment->hasher)->value, 'snapshot' => $snapshot->toArray()];
        $parameters = ['tenant' => $action->tenant->value, 'action' => $action->value, 'key' => $intent->effectKey];
        if ($insert) {
            $db->execute('INSERT INTO ' . $db->table('effect_intents') . '
                (tenant_id,id,action_ref,effect_key,owner_namespace,owner_operation_key,request_hash,request_ref,request_context,claim_epoch)
                VALUES (:tenant,:id,:action,:key,:namespace,:operation,:hash,:ref,CAST(:context AS jsonb),:epoch)', $parameters + [
                    'id' => $event->eventId, 'namespace' => $intent->ownerNamespace, 'operation' => $intent->ownerOperationKey,
                    'hash' => substr($intent->requestHash->value, 8), 'ref' => $intent->requestRef->value,
                    'context' => json_encode($authorization, JSON_THROW_ON_ERROR), 'epoch' => $claim->state->fencingEpoch]);
        } else {
            $row = $db->row('SELECT * FROM ' . $db->table('effect_intents') . ' WHERE tenant_id=:tenant AND action_ref=:action AND effect_key=:key', $parameters);
            if ($row === null || Connection::text($row, 'request_hash') !== substr($intent->requestHash->value, 8)
                || Connection::text($row, 'request_ref') !== $intent->requestRef->value
                || Connection::text($row, 'owner_namespace') !== $intent->ownerNamespace
                || Connection::text($row, 'owner_operation_key') !== $intent->ownerOperationKey) {
                throw new IntegrityConflict('Saved effect identity conflict');
            }
        }
    }

    private function fold(ActionId $action): Projection
    {
        $db = $this->environment->db;
        $reducer = new ActionReducer('2026-09', new JournalHashChain(new Canonicalizer()));
        $state = new FoldedAction();
        $rules = new EventStoreSupport();
        do {
            $page = $this->journal->load($action->tenant->value, $action->value, $state->seq, 1000);
            foreach ($page->events as $event) {
                $row = $db->row('SELECT content_hash FROM ' . $db->table('agentools_events') . ' WHERE tenant_id=:tenant AND event_id=:id',
                    ['tenant' => $action->tenant->value, 'id' => $event->eventId]);
                $hash = $rules->contentHash($action->tenant->value, $action->value, new UncommittedEvent($event->eventId,
                    $event->kind, $event->eventVersion, $event->specRevision, $event->actor, $event->timestamp, $event->payload, $event->epoch));
                if ($row === null || !hash_equals(Connection::text($row, 'content_hash'), $hash)) {
                    throw new IntegrityConflict('Journal content hash conflict');
                }
                $state = $reducer->apply($state, $event);
            }
        } while ($page->hasMore);
        $head = $db->row('SELECT current_seq FROM ' . $db->table('agentools_event_streams') . ' WHERE tenant_id=:tenant AND action_id=:action',
            ['tenant' => $action->tenant->value, 'action' => $action->value]);
        if ($head === null || Connection::number($head, 'current_seq') !== $state->seq) {
            throw new IntegrityConflict('Journal head mismatch');
        }
        return new Projection($state);
    }

    private function writeProjection(ActionId $action, Projection $projection): void
    {
        $db = $this->environment->db;
        $db->execute('INSERT INTO ' . $db->table('action_current') . '(tenant_id,id,version,state,digest)
            VALUES (:tenant,:id,:version,CAST(:state AS jsonb),:digest)
            ON CONFLICT (tenant_id,id) DO UPDATE SET version=EXCLUDED.version,state=EXCLUDED.state,digest=EXCLUDED.digest', [
                'tenant' => $action->tenant->value, 'id' => $action->value, 'version' => $projection->state->seq,
                'state' => $projection->json(), 'digest' => $projection->digest()]);
    }

    /** @return array<string, mixed> */
    private function named(string $json): array
    {
        $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new StorageFailure();
        }
        $result = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                throw new StorageFailure();
            }
            $result[$key] = $value;
        }
        return $result;
    }
}
