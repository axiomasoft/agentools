<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Actions;

use AgenTools\Action\{Actor, ActionReducer, FoldedAction, JournalHashChain};
use AgenTools\Persistence\{EventStoreSupport, UncommittedEvent};
use AgenTools\Schema\Canonicalizer;
use AgenToolsPlatform\Core\Actions\{ActionLifecycle, ActionPolicy, ActionRegistration, ActionRegistry, ActionSubmission, ActionView, CancelRequest, InputSchemaValidator, ReconcileRequest};
use AgenToolsPlatform\Core\Common\{ActionId, ActionReceipt, ContractViolation, ErrorCode, IntegrityConflict, PayloadHash};
use AgenToolsPlatform\Core\Profiles\DefinitionRef;
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Runtime\{Connection, RepositoryContext};

/** Durable headless intake only. Dispatch remains with the existing dated lifecycle/owner. */
final readonly class SubmissionLifecycle implements ActionLifecycle
{
    public function __construct(private RepositoryContext $environment, private ActionRegistry $registry,
        private ActionPolicy $policy, private InputSchemaValidator $schemas)
    {
    }

    public function submit(ActionSubmission $submission, ActionRegistration $registration): ActionView
    {
        $request = $submission->request;
        $context = $request->context;
        $this->environment->authorize($context);
        $entry = $this->registry->executionView($request->definition, $this->policy->current($context));
        if ($registration->definition->ref->toArray() !== $entry->definition->ref->toArray()) {
            throw new IntegrityConflict('Definition binding conflict');
        }
        $this->schemas->assertValid($entry->definition->inputSchema, $submission->inputJson);
        $db = $this->environment->db;
        $view = $db->transaction(function () use ($db, $request, $context): ActionView {
            $tenant = $context->identity->tenant;
            // Serialize all aliases of the same submission, including competing connections.
            $db->execute('SELECT pg_advisory_xact_lock(hashtextextended(:key,0))', [
                'key' => $this->environment->hasher->hash($tenant, 'action-submission-lock', $request->idempotencyKey->value)->value]);
            $saved = $db->row('SELECT * FROM ' . $db->table('action_submissions') . ' WHERE tenant_id=:tenant AND submission_key=:key',
                ['tenant' => $tenant->value, 'key' => $request->idempotencyKey->value]);
            if ($saved !== null) {
                $this->assertScope($saved, $context);
                if (!hash_equals(Connection::text($saved, 'request_hash'), $request->requestHash->value)) {
                    throw new IntegrityConflict('Action submission identity conflict');
                }
                // The immutable acceptance receipt, even if status has subsequently advanced.
                return $this->accepted(new ActionId($tenant, Connection::text($saved, 'id')), $this->definition($saved));
            }
            $id = new ActionId($tenant, substr($this->environment->hasher->hash($tenant, 'direct-action',
                [$request->originRef->value, $request->idempotencyKey->value])->value, 8));
            if ($request->proposalId !== null) {
                $map = $db->row('SELECT p.action_ref,p.payload_hash FROM ' . $db->table('proposal_map') . ' p
                    JOIN ' . $db->table('runs') . ' r ON r.tenant_id=p.tenant_id AND r.id=p.run_id
                    JOIN ' . $db->table('turns') . ' t ON t.tenant_id=r.tenant_id AND t.id=r.turn_id
                    WHERE p.tenant_id=:tenant AND p.id=:proposal AND t.session_id=:session', [
                        'tenant' => $tenant->value, 'proposal' => $request->proposalId->value, 'session' => $context->session->value]);
                if ($map === null) {
                    throw new ContractViolation(ErrorCode::AccessDenied);
                }
                if (Connection::text($map, 'payload_hash') !== substr($request->inputHash->value, 8)) {
                    throw new IntegrityConflict('Saved proposal input conflict');
                }
                $id = new ActionId($tenant, Connection::text($map, 'action_ref'));
            } else {
                $db->execute('INSERT INTO ' . $db->table('action_origins') . '(tenant_id,id) VALUES (:tenant,:id)',
                    ['tenant' => $tenant->value, 'id' => $id->value]);
            }
            $deadline = $db->row('SELECT CAST(:deadline AS timestamptz)>clock_timestamp() AS valid', ['deadline' => $request->deadline->value]);
            if ($deadline === null || $deadline['valid'] !== true) {
                throw new ContractViolation(ErrorCode::DeadlineExceeded);
            }
            $db->execute('INSERT INTO ' . $db->table('action_submissions') . '
                (tenant_id,id,submission_key,origin_ref,proposal_id,actor_ref,scope_hash,request_hash,definition_ref,input_ref,input_hash,deadline_at)
                VALUES (:tenant,:id,:key,:origin,:proposal,:actor,:scope,:hash,CAST(:definition AS jsonb),:input,:input_hash,:deadline)', [
                    'tenant' => $tenant->value, 'id' => $id->value, 'key' => $request->idempotencyKey->value,
                    'origin' => $request->originRef->value, 'proposal' => $request->proposalId?->value,
                    'actor' => $context->identity->actorRef->value, 'scope' => $request->scopeHash->value,
                    'hash' => $request->requestHash->value, 'definition' => json_encode($request->definition->toArray(), JSON_THROW_ON_ERROR),
                    'input' => $request->inputRef->value, 'input_hash' => $request->inputHash->value, 'deadline' => $request->deadline->value]);
            $eventId = substr($this->environment->hasher->hash($tenant, 'action-created-event', $id->value)->value, 8);
            $now = $db->row('SELECT to_char(clock_timestamp() AT TIME ZONE \'UTC\',\'YYYY-MM-DD"T"HH24:MI:SS.US"Z"\') AS ts')
                ?? throw new IntegrityConflict('Missing database time');
            $event = new UncommittedEvent($eventId, 'action_created', 1, '2026-09',
                new Actor('user', $context->identity->actorRef->value), Connection::text($now, 'ts'), []);
            $db->journal()->appendInTransaction($tenant->value, $id->value, 0, [$event]);
            $projection = $this->projection($id);
            $db->execute('INSERT INTO ' . $db->table('action_current') . '(tenant_id,id,version,state,digest)
                VALUES (:tenant,:id,1,CAST(:state AS jsonb),:digest)', ['tenant' => $tenant->value, 'id' => $id->value,
                    'state' => $projection->json(), 'digest' => $projection->digest()]);
            $db->execute('INSERT INTO ' . $db->table('outbox') . '
                (tenant_id,id,event_key,payload_hash,event_type,aggregate_ref,deadline_at,retention_until)
                VALUES (:tenant,:id,:event,:hash,\'action.journaled\',:action,:deadline,CAST(:retention AS timestamptz)+interval \'7 days\')', [
                    'tenant' => $tenant->value, 'id' => 'action.event:' . $eventId, 'event' => 'action.event:' . $eventId,
                    'hash' => substr((new EventStoreSupport())->contentHash($tenant->value, $id->value, $event), 8), 'action' => $id->value,
                    'deadline' => $request->deadline->value, 'retention' => $request->deadline->value]);
            return $this->accepted($id, $request->definition);
        });
        $this->environment->authorize($context);
        $this->registry->executionView($view->definition, $this->policy->current($context));
        return $view;
    }

    public function status(ActionId $id, ExecutionContext $context): ActionView
    {
        $id->tenant->assertSame($context->identity->tenant);
        $this->environment->authorize($context);
        $db = $this->environment->db;
        // Bind before reading journal/projection. UUID knowledge never grants disclosure.
        $row = $db->row('SELECT * FROM ' . $db->table('action_submissions') . '
            WHERE tenant_id=:tenant AND id=:id AND actor_ref=:actor AND scope_hash=:scope', [
                'tenant' => $id->tenant->value, 'id' => $id->value, 'actor' => $context->identity->actorRef->value,
                'scope' => $context->scopeHash($this->environment->hasher)->value]);
        if ($row === null) {
            throw new ContractViolation(ErrorCode::AccessDenied);
        }
        $definition = $this->definition($row);
        $this->registry->executionView($definition, $this->policy->current($context));
        $projection = $db->transaction(function () use ($db, $id): Projection {
            $saved = $db->row('SELECT state,digest,version FROM ' . $db->table('action_current') . '
                WHERE tenant_id=:tenant AND id=:id FOR SHARE', ['tenant' => $id->tenant->value, 'id' => $id->value]);
            $projection = $this->projection($id);
            if ($saved === null || !hash_equals(Connection::text($saved, 'digest'), $projection->digest())
                || Connection::number($saved, 'version') !== $projection->state->seq
                || (new Canonicalizer())->canonicalize(json_decode(Connection::text($saved, 'state'), false, 512, JSON_THROW_ON_ERROR)) !== $projection->json()) {
                throw new IntegrityConflict('Action projection requires verified recovery');
            }
            return $projection;
        });
        $this->environment->authorize($context);
        $this->registry->executionView($definition, $this->policy->current($context));
        $status = $projection->state->status->value ?? throw new IntegrityConflict('Missing accepted action');
        $next = match ($status) {
            'completed', 'failed', 'cancelled', 'expired', 'rejected' => 'none',
            'awaiting_confirmation' => 'confirm',
            'applying' => 'reconcile',
            'reconciliation_required' => 'manual_resolution',
            default => 'status',
        };
        return new ActionView(new ActionReceipt($id, $status, $projection->state->seq), $definition, $next);
    }

    /** @param array<string,mixed> $row */
    private function assertScope(array $row, ExecutionContext $context): void
    {
        if (Connection::text($row, 'actor_ref') !== $context->identity->actorRef->value
            || !hash_equals(Connection::text($row, 'scope_hash'), $context->scopeHash($this->environment->hasher)->value)) {
            throw new ContractViolation(ErrorCode::AccessDenied);
        }
    }

    /** @param array<string,mixed> $row */
    private function definition(array $row): DefinitionRef
    {
        $value = json_decode(Connection::text($row, 'definition_ref'), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($value) || !is_string($value['id'] ?? null) || !is_string($value['version'] ?? null)
            || !is_string($value['hash'] ?? null)) {
            throw new IntegrityConflict('Invalid pinned definition');
        }
        return new DefinitionRef($value['id'], $value['version'], new PayloadHash($value['hash']));
    }

    private function accepted(ActionId $id, DefinitionRef $definition): ActionView
    {
        return new ActionView(new ActionReceipt($id, 'created', 1), $definition, 'status');
    }

    private function projection(ActionId $id): Projection
    {
        $reducer = new ActionReducer('2026-09', new JournalHashChain(new Canonicalizer()));
        $state = new FoldedAction();
        do {
            $page = $this->environment->db->journal()->load($id->tenant->value, $id->value, $state->seq, 1000);
            foreach ($page->events as $event) {
                $saved = $this->environment->db->row('SELECT content_hash FROM ' . $this->environment->db->table('agentools_events') . '
                    WHERE tenant_id=:tenant AND event_id=:id', ['tenant' => $id->tenant->value, 'id' => $event->eventId]);
                $hash = (new EventStoreSupport())->contentHash($id->tenant->value, $id->value, new UncommittedEvent($event->eventId,
                    $event->kind, $event->eventVersion, $event->specRevision, $event->actor, $event->timestamp, $event->payload, $event->epoch));
                if ($saved === null || !hash_equals(Connection::text($saved, 'content_hash'), $hash)) {
                    throw new IntegrityConflict('Journal content hash conflict');
                }
                $state = $reducer->apply($state, $event);
            }
        } while ($page->hasMore);
        $head = $this->environment->db->row('SELECT current_seq FROM ' . $this->environment->db->table('agentools_event_streams') . '
            WHERE tenant_id=:tenant AND action_id=:id', ['tenant' => $id->tenant->value, 'id' => $id->value]);
        if ($head === null || Connection::number($head, 'current_seq') !== $state->seq) {
            throw new IntegrityConflict('Journal head mismatch');
        }
        return new Projection($state);
    }

    public function cancel(ActionId $id, CancelRequest $request): ActionView
    {
        $this->status($id,$request->context);
        (new \AgenToolsPlatform\Postgres\Confirmation\CancellationRepository($this->environment))->request($id,$request);
        return $this->status($id,$request->context);
    }

    public function reconcile(ActionId $id, ReconcileRequest $request): ActionView
    {
        // Never reinterpret an absent owner lookup as a safe repeat of a write.
        throw new ContractViolation(ErrorCode::CapabilityUnavailable);
    }
}
