<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Inbox;

use AgenToolsPlatform\Core\Common\{AcceptedTurn, ContractViolation, ErrorCode, IdentityKeys, IntegrityConflict, RunStatus};
use AgenToolsPlatform\Core\Context\ContextSnapshot;
use AgenToolsPlatform\Core\Security\TrustedInput;
use AgenToolsPlatform\Postgres\Runtime\{Connection, RepositoryContext, StorageFailure};

final readonly class IntakeRepository
{
    public function __construct(private RepositoryContext $environment, private ?IntakeAdmission $admission = null)
    {
    }

    public function accept(TrustedInput $input, ContextSnapshot $snapshot): AcceptedTurn
    {
        $context = $input->context;
        $this->environment->authorize($context, $snapshot);
        $sourceKey = $input->source->key($this->environment->hasher);
        $hash = $input->source->payloadHash($input->payload, $this->environment->hasher);
        $keys = new IdentityKeys($this->environment->hasher);
        $turn = $keys->turn($input->source);
        $run = $keys->run($turn);
        $receipt = new AcceptedTurn($sourceKey, $hash, $turn, $run);
        $identity = ['tenant' => $turn->tenant->value, 'source' => $input->source->kind,
            'event' => SourceKey::event($input->source)];
        $db = $this->environment->db;
        return $db->transaction(function () use ($db, $input, $snapshot, $context, $identity, $receipt): AcceptedTurn {
            $this->admission?->assertCurrent($db, $input);
            $session = $db->row('SELECT instance_id FROM ' . $db->table('sessions') . ' WHERE tenant_id=:tenant AND id=:session',
                ['tenant' => $identity['tenant'], 'session' => $context->session->value]);
            if ($session === null || Connection::text($session, 'instance_id') !== $input->source->instance->value) {
                throw new ContractViolation(ErrorCode::AccessDenied);
            }
            $inserted = $db->row('INSERT INTO ' . $db->table('turns') . '(tenant_id,id,session_id,source,source_event_key,payload_hash,input_ref)
                VALUES (:tenant,:id,:session,:source,:event,:hash,:input) ON CONFLICT (tenant_id,source,source_event_key) DO NOTHING RETURNING id',
                $identity + ['id' => $receipt->turnId->value, 'session' => $context->session->value,
                    'hash' => substr($receipt->payloadHash->value, 8), 'input' => $input->payload->inputRef->value]);
            if ($inserted === null) {
                $saved = $db->row('SELECT t.id,t.session_id,t.payload_hash,t.input_ref,i.id AS source_key,r.id AS run_id
                    FROM ' . $db->table('turns') . ' t JOIN ' . $db->table('inbox') . ' i
                    ON i.tenant_id=t.tenant_id AND i.turn_id=t.id AND i.source=t.source AND i.source_event_key=t.source_event_key
                    JOIN ' . $db->table('runs') . ' r ON r.tenant_id=t.tenant_id AND r.turn_id=t.id AND r.id=:run
                    WHERE t.tenant_id=:tenant AND t.session_id=:session AND t.source=:source AND t.source_event_key=:event',
                    $identity + ['run' => $receipt->runId->value, 'session' => $context->session->value]);
                if ($saved === null || Connection::text($saved, 'id') !== $receipt->turnId->value
                    || Connection::text($saved, 'source_key') !== $receipt->sourceKey->value
                    || Connection::text($saved, 'session_id') !== $context->session->value
                    || Connection::text($saved, 'input_ref') !== $input->payload->inputRef->value
                    || Connection::text($saved, 'payload_hash') !== substr($receipt->payloadHash->value, 8)) {
                    throw new IntegrityConflict('Source identity payload or binding conflict');
                }
                // Return the original acceptance receipt, not the current run status.
                return $receipt;
            }
            $checkpoint = ['identity_scheme' => 'platform/v1', 'accepted_context' => $context->toArray(),
                'accepted_snapshot' => $snapshot->toArray(), 'source' => $input->source->toArray(),
                'input' => $input->payload->toArray(), 'source_key' => $receipt->sourceKey->value];
            $db->execute('INSERT INTO ' . $db->table('runs') . '(tenant_id,id,turn_id,status,checkpoint)
                VALUES (:tenant,:id,:turn,:status,CAST(:checkpoint AS jsonb))', [
                'tenant' => $identity['tenant'], 'id' => $receipt->runId->value, 'turn' => $receipt->turnId->value,
                'status' => RunStatus::Accepted->value, 'checkpoint' => json_encode($checkpoint, JSON_THROW_ON_ERROR)]);
            $db->execute('INSERT INTO ' . $db->table('inbox') . '(tenant_id,id,source,source_event_key,payload_hash,turn_id,status)
                VALUES (:tenant,:id,:source,:event,:hash,:turn,:status)', $identity + [
                'id' => $receipt->sourceKey->value, 'hash' => substr($receipt->payloadHash->value, 8),
                'turn' => $receipt->turnId->value, 'status' => 'completed']);
            // The outbox IS the durable wake intent. Queue transport/scanner is a later owner.
            $eventKey = 'run.accepted:' . $receipt->runId->value;
            $wakeupHash = $this->environment->hasher->hash($receipt->runId->tenant, 'intake-wakeup', $receipt->toArray());
            $db->execute('INSERT INTO ' . $db->table('outbox') . '(tenant_id,id,event_key,payload_hash,event_type,aggregate_ref,deadline_at,retention_until)
                VALUES (:tenant,:id,:event,:hash,:type,:aggregate,clock_timestamp()+interval \'1 day\',clock_timestamp()+interval \'8 days\')', [
                'tenant' => $identity['tenant'], 'id' => $eventKey, 'event' => $eventKey,
                'hash' => substr($wakeupHash->value, 8), 'type' => 'run.accepted', 'aggregate' => $receipt->runId->value]);
            return $receipt;
        });
    }
}
