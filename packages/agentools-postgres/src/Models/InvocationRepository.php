<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Models;

use AgenToolsPlatform\Core\Budget\{Admission, AdmissionRequest};
use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, IntegrityConflict, PayloadHash, Ref, RunId};
use AgenToolsPlatform\Core\Runtime\{AdvanceLease, ExchangeResult, InvocationCheckpoint, InvocationJournal, InvocationPlan, InvocationRefusal, ResponsePublication, ResponsePublisher, SavedProposal};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Runtime\{Connection, RepositoryContext, StorageFailure};

/** Durable one-loop technical journal on the existing platform tables. No owner IO inside Tx. */
final readonly class InvocationRepository implements InvocationJournal
{
    public function __construct(private RepositoryContext $environment, private ?ResponsePublisher $publisher = null) {}

    /** Native payment correlation only; the external Budget owner still decides price and settlement.
     * @return array<string,mixed>
     */
    public function payment(ExecutionContext $context, Ref $reservation, ?Ref $usage = null): array
    {
        $this->environment->authorize($context); $context->session->tenant->assertSame($reservation->tenant);
        $usage?->tenant->assertSame($reservation->tenant); $db = $this->environment->db;
        $row = $db->row('SELECT s.state_ref FROM '.$db->table('run_steps').' s
            JOIN '.$db->table('runs').' r ON r.tenant_id=s.tenant_id AND r.id=s.run_id
            JOIN '.$db->table('turns').' t ON t.tenant_id=r.tenant_id AND t.id=r.turn_id
            WHERE s.tenant_id=:tenant AND t.session_id=:session
            AND CAST(s.state_ref AS jsonb)->\'admission\'->>\'reservation_ref\'=:reservation
            AND (CAST(:usage AS text) IS NULL OR EXISTS (SELECT 1 FROM '.$db->table('usage_versions').' u
                WHERE u.tenant_id=s.tenant_id AND u.invocation_id=CAST(s.state_ref AS jsonb)->>\'invocation_id\' AND u.id=:usage_id))',
            ['tenant' => $reservation->tenant->value, 'session' => $context->session->value,
                'reservation' => $reservation->value, 'usage' => $usage?->value, 'usage_id' => $usage?->value]);
        if ($row === null) { throw new ContractViolation(ErrorCode::AccessDenied); }
        return $this->decode(Connection::text($row, 'state_ref'));
    }

    /** Current session-bound native admission state; null is not a statement about an external reservation.
     * @return array<string,mixed>|null
     */
    public function admission(ExecutionContext $context, AdmissionRequest $request): ?array
    {
        $this->environment->authorize($context);
        $context->session->tenant->assertSame($request->operationKey->tenant);
        $db = $this->environment->db;
        $row = $db->row('SELECT s.state_ref FROM '.$db->table('run_steps').' s
            JOIN '.$db->table('runs').' r ON r.tenant_id=s.tenant_id AND r.id=s.run_id
            JOIN '.$db->table('turns').' t ON t.tenant_id=r.tenant_id AND t.id=r.turn_id
            WHERE s.tenant_id=:tenant AND t.session_id=:session AND CAST(s.state_ref AS jsonb)->>\'admission_key\'=:key',
            ['tenant' => $context->session->tenant->value, 'session' => $context->session->value, 'key' => $request->operationKey->value]);
        if ($row === null) { return null; }
        $data = $this->decode(Connection::text($row, 'state_ref'));
        if (($data['admission_hash'] ?? null) !== $request->hash->value) { throw new IntegrityConflict('Admission identity conflict'); }
        $admission = $data['admission'] ?? null;
        if ($admission !== null && !is_array($admission)) { throw new StorageFailure(); }
        return $admission === null ? null : $this->named($admission);
    }

    /** @return array{reservation: Ref, usage: Ref} A native accepted exchange proves a request, not its price. */
    public function settlement(ExecutionContext $context, RunId $run): array
    {
        $this->environment->authorize($context); $context->session->tenant->assertSame($run->tenant);
        $db = $this->environment->db;
        $row = $db->row('SELECT s.state_ref,u.id AS usage FROM '.$db->table('run_steps').' s
            JOIN '.$db->table('runs').' r ON r.tenant_id=s.tenant_id AND r.id=s.run_id
            JOIN '.$db->table('turns').' t ON t.tenant_id=r.tenant_id AND t.id=r.turn_id
            JOIN '.$db->table('invocation_intents').' i ON i.tenant_id=s.tenant_id AND i.run_id=s.run_id AND i.step_key=s.step_key
            JOIN '.$db->table('invocation_results').' x ON x.tenant_id=i.tenant_id AND x.invocation_id=i.id AND x.outcome=\'success\'
            JOIN '.$db->table('usage_versions').' u ON u.tenant_id=i.tenant_id AND u.invocation_id=i.id
            WHERE r.tenant_id=:tenant AND r.id=:run AND t.session_id=:session AND r.status=\'completed\'
            ORDER BY u.usage_version DESC LIMIT 1', ['tenant' => $run->tenant->value, 'run' => $run->value, 'session' => $context->session->value]);
        if ($row === null) { throw new ContractViolation(ErrorCode::ManualResolutionRequired); }
        $data = $this->decode(Connection::text($row, 'state_ref'));
        $admission = $data['admission'] ?? null;
        $reservation = is_array($admission) ? ($admission['reservation_ref'] ?? null) : null;
        if (!is_string($reservation) || ($data['request_count'] ?? null) !== 1) {
            throw new ContractViolation(ErrorCode::ManualResolutionRequired);
        }
        return ['reservation' => new Ref($run->tenant, $reservation), 'usage' => new Ref($run->tenant, Connection::text($row, 'usage'))];
    }

    /** Recover the already committed response/export after process death, without reopening generation. */
    public function published(ExecutionContext $context, RunId $run): ?ResponsePublication
    {
        $context->session->tenant->assertSame($run->tenant);
        $this->environment->authorize($context);
        $db = $this->environment->db;
        $row = $db->row('SELECT p.payload_ref_id,p.payload_hash,d.delivery_key FROM '.$db->table('responses').' p
            JOIN '.$db->table('runs').' r ON r.tenant_id=p.tenant_id AND r.id=p.run_id
            JOIN '.$db->table('turns').' t ON t.tenant_id=r.tenant_id AND t.id=r.turn_id
            JOIN '.$db->table('delivery_intents').' d ON d.tenant_id=p.tenant_id AND d.response_id=p.id
            WHERE r.tenant_id=:tenant AND r.id=:run AND t.session_id=:session
            AND r.status=\'completed\' AND p.response_key=\'1\' AND d.payload_hash=p.payload_hash',
            ['tenant' => $run->tenant->value, 'run' => $run->value, 'session' => $context->session->value]);
        return $row === null ? null : new ResponsePublication(new Ref($run->tenant, Connection::text($row, 'payload_ref_id')),
            new PayloadHash('sha-256:'.Connection::text($row, 'payload_hash')), Connection::text($row, 'delivery_key'));
    }

    public function open(ExecutionContext $context, AdvanceLease $lease, InvocationPlan $plan, AdmissionRequest $admission): InvocationCheckpoint
    {
        $this->authorize($context, $lease, $plan);
        $db = $this->environment->db;
        return $db->transaction(function () use ($db, $context, $lease, $plan, $admission): InvocationCheckpoint {
            $run = $this->lock($context, $lease);
            if (Connection::text($run, 'turn_id') !== $plan->turnId->value) { throw new IntegrityConflict('Invocation Turn changed'); }
            $row = $db->row('SELECT state_ref FROM ' . $db->table('run_steps') . ' WHERE tenant_id=:tenant AND run_id=:run AND step_key=:step FOR UPDATE',
                $this->key($plan));
            $hash = $this->environment->hasher->hash($plan->runId->tenant, 'invocation-plan', $plan->identity())->value;
            if ($row !== null) {
                $data = $this->decode(Connection::text($row, 'state_ref'));
                if (($data['invocation_id'] ?? null) === $plan->request->invocationId->value) {
                    if (($data['plan_hash'] ?? null) !== $hash || ($data['admission_hash'] ?? null) !== $admission->hash->value) {
                        throw new IntegrityConflict('Invocation/admission content conflict');
                    }
                    return new InvocationCheckpoint($lease, $data);
                }
                if (($data['invocation_id'] ?? null) !== $plan->parentInvocation || ($data['stage'] ?? null) !== 'unknown') {
                    throw new ContractViolation(ErrorCode::PolicyConflict);
                }
            } else {
                if ($plan->parentInvocation !== null) {
                    $parent = $db->row('SELECT state_ref FROM ' . $db->table('run_steps') . ' WHERE tenant_id=:tenant AND run_id=:run
                        AND CAST(state_ref AS jsonb)->>\'invocation_id\'=:parent', [
                            'tenant' => $plan->runId->tenant->value, 'run' => $plan->runId->value, 'parent' => $plan->parentInvocation]);
                    if ($parent === null || ($this->decode(Connection::text($parent, 'state_ref'))['stage'] ?? null) !== 'continued') {
                        throw new ContractViolation(ErrorCode::PolicyConflict);
                    }
                }
                $busy = $db->row('SELECT 1 FROM ' . $db->table('run_steps') . ' WHERE tenant_id=:tenant AND run_id=:run
                    AND (state_ref IS NULL OR CAST(state_ref AS jsonb)->>\'stage\' NOT IN (\'continued\',\'complete\')) LIMIT 1',
                    ['tenant' => $plan->runId->tenant->value, 'run' => $plan->runId->value]);
                if ($busy !== null) { throw new ContractViolation(ErrorCode::PolicyConflict); }
            }
            $all = $db->execute('SELECT state_ref FROM ' . $db->table('run_steps') . ' WHERE tenant_id=:tenant AND run_id=:run',
                ['tenant' => $plan->runId->tenant->value, 'run' => $plan->runId->value])->fetchAll(\PDO::FETCH_ASSOC);
            $attempts = 1; $units = $plan->estimatedUnits; $tools = 0; $errors = 0;
            foreach ($all as $step) {
                $prior = $this->decode(Connection::text($this->named($step), 'state_ref'));
                if (($prior['bounds'] ?? null) !== $plan->bounds->toArray()) { throw new ContractViolation(ErrorCode::PolicyConflict); }
                $attempts += $this->integer($prior, 'attempts');
                $units += $this->integer($prior, 'estimated_units');
                $tools += $this->integer($prior, 'tool_count');
                $errors += $this->integer($prior, 'errors');
            }
            if ($attempts > $plan->bounds->maxInvocations || $units > $plan->bounds->maxEstimatedUnits
                || $tools > $plan->bounds->maxTools || $errors >= $plan->bounds->maxRepeatedErrors) {
                throw new ContractViolation(ErrorCode::PolicyConflict);
            }
            $data = ['stage' => 'admission_pending', 'invocation_id' => $plan->request->invocationId->value,
                'plan_hash' => $hash, 'plan' => $plan->identity(), 'admission_key' => $admission->operationKey->value,
                'admission_hash' => $admission->hash->value, 'bounds' => $plan->bounds->toArray(), 'attempts' => 1,
                'estimated_units' => $plan->estimatedUnits, 'tool_count' => 0, 'errors' => 0, 'usage_version' => 0, 'submitted' => []];
            if ($row !== null) {
                $previous = $this->decode(Connection::text($row, 'state_ref'));
                // Replacement preserves cumulative counters and the previous unknown receipt/ref.
                $data['attempts'] += $this->integer($previous, 'attempts');
                $data['estimated_units'] += $this->integer($previous, 'estimated_units');
                $data['errors'] = $this->integer($previous, 'errors');
                $data['prior_unknown'] = $previous['result_ref'] ?? null;
            } else {
                $db->execute('INSERT INTO ' . $db->table('run_steps') . '(tenant_id,id,run_id,step_key,state_ref)
                    VALUES (:tenant,:id,:run,:step,:state)', $this->key($plan) + [
                        'id' => $this->stepId($plan), 'state' => json_encode($data, JSON_THROW_ON_ERROR)]);
            }
            if (!in_array(Connection::text($run, 'status'), ['preparing','running','waiting_external'], true)) {
                throw new ContractViolation(ErrorCode::PolicyConflict);
            }
            return $this->write($lease, $plan, $data, 'running');
        });
    }

    public function admitted(ExecutionContext $context, InvocationPlan $plan, InvocationCheckpoint $saved, Admission $admission): InvocationCheckpoint
    {
        if ($admission->admissionRef !== null) { $plan->runId->tenant->assertSame($admission->admissionRef->tenant); }
        return $this->mutate($context, $plan, $saved, ['admission_pending'], function (array $data) use ($admission): array {
            $data['admission'] = $admission->toArray();
            $data['reservation_status'] = $admission->status === 'unknown' ? 'unknown' : ($admission->status === 'admitted' ? 'active' : 'pending');
            $data['stage'] = $admission->status === 'admitted' ? 'admitted' : 'admission_pending'; return $data;
        });
    }

    public function outbound(ExecutionContext $context, InvocationPlan $plan, InvocationCheckpoint $saved): InvocationCheckpoint
    {
        return $this->mutate($context, $plan, $saved, ['admitted'], function (array $data) use ($plan): array {
            $db = $this->environment->db;
            $admission = $data['admission'] ?? null;
            if (!is_array($admission) || !is_string($admission['admission_ref'] ?? null) || !is_string($admission['expires_at'] ?? null)) {
                throw new StorageFailure();
            }
            $db->execute('INSERT INTO ' . $db->table('invocation_intents') . '
                (tenant_id,id,run_id,step_key,invocation_key,request_hash,request_ref,model_ref,admission_ref)
                SELECT :tenant,:id,:run,:step,:invocation,:hash,:request,:model,:admission
                WHERE CAST(:expires AS timestamptz)>clock_timestamp() AND CAST(:deadline AS timestamptz)>clock_timestamp()', [
                    'tenant' => $plan->runId->tenant->value, 'id' => $plan->request->invocationId->value,
                    'run' => $plan->runId->value, 'step' => $plan->stepKey, 'invocation' => $plan->request->invocationId->value,
                    'hash' => substr($plan->requestHash->value, 8), 'request' => $plan->requestRef->value,
                    'model' => $plan->deployment->ref->value, 'admission' => $admission['admission_ref'],
                    'expires' => $admission['expires_at'], 'deadline' => $plan->request->deadline->value]);
            $row = $db->row('SELECT 1 FROM ' . $db->table('invocation_intents') . ' WHERE tenant_id=:tenant AND id=:id',
                ['tenant' => $plan->runId->tenant->value, 'id' => $plan->request->invocationId->value]);
            if ($row === null) { throw new ContractViolation(ErrorCode::AdmissionDenied); }
            $data['stage'] = 'outbound'; $data['request_count'] = null;
            $data['possible_cost'] = true; $data['reservation_status'] = 'active'; return $data;
        });
    }

    public function refused(ExecutionContext $context, InvocationPlan $plan, InvocationCheckpoint $saved, InvocationRefusal $refusal): InvocationCheckpoint
    {
        return $this->mutate($context, $plan, $saved, ['admitted', 'outbound'], function (array $data) use ($plan, $refusal): array {
            if ($data['stage'] === 'outbound') {
                // Keep the immutable intent, with a known failed result instead of a potentially sent outcome.
                $hash = $this->environment->hasher->hash($plan->runId->tenant, 'invocation-refusal', [$plan->identity(), $refusal->toArray()]);
                $this->environment->db->execute('INSERT INTO ' . $this->environment->db->table('invocation_results') . '
                    (tenant_id,id,invocation_id,payload_hash,outcome) VALUES (:tenant,:id,:invocation,:hash,\'failed\')', [
                        'tenant' => $plan->runId->tenant->value, 'id' => $plan->request->invocationId->value,
                        'invocation' => $plan->request->invocationId->value, 'hash' => substr($hash->value, 8)]);
            }
            $data['stage'] = 'refused'; $data['refusal'] = $refusal->toArray();
            $data['request_count'] = 0; $data['possible_cost'] = false;
            $data['errors'] = $this->integer($data, 'errors') + 1;
            // Reservation ownership stays with Budget; no invented settlement/release or provider usage.
            return $data;
        }, 'waiting_external', true);
    }

    public function result(ExecutionContext $context, InvocationPlan $plan, InvocationCheckpoint $saved, Ref $payload, PayloadHash $hash, ExchangeResult $result, ?Ref $resolutionEvidence = null): InvocationCheckpoint
    {
        $plan->runId->tenant->assertSame($payload->tenant);
        $hash->assertSame($result->hash());
        if ($resolutionEvidence !== null) { $plan->runId->tenant->assertSame($resolutionEvidence->tenant); }
        if ($saved->stage() === 'unknown' && $resolutionEvidence === null) { throw new ContractViolation(ErrorCode::PolicyConflict); }
        return $this->mutate($context, $plan, $saved, ['outbound','unknown'], function (array $data) use ($context, $plan, $payload, $hash, $result, $resolutionEvidence): array {
            $db = $this->environment->db;
            $registered = $db->row('SELECT 1 FROM ' . $db->table('payload_refs') . '
                WHERE tenant_id=:tenant AND id=:id AND session_id=:session AND content_hash=:hash
                AND purged_at IS NULL AND purge_pending_at IS NULL AND retention_until>clock_timestamp()', [
                    'tenant' => $payload->tenant->value, 'id' => $payload->value, 'session' => $context->session->value, 'hash' => substr($hash->value, 8)]);
            if ($registered === null) { throw new ContractViolation(ErrorCode::AccessDenied); }
            $bounded = count($result->proposals) + $this->totalTools($plan) > $plan->bounds->maxTools;
            $tenant = $plan->runId->tenant->value; $invocation = $plan->request->invocationId->value;
            $version = $this->integer($data, 'usage_version') + 1;
            $usageId = (new \AgenToolsPlatform\Core\Common\IdentityKeys($this->environment->hasher))->usage($plan->request->invocationId, $version)->value;
            if ($data['stage'] === 'outbound') {
                $db->execute('INSERT INTO ' . $db->table('invocation_results') . '(tenant_id,id,invocation_id,payload_hash,outcome,result_ref)
                    VALUES (:tenant,:id,:invocation,:hash,:outcome,:result)', ['tenant' => $tenant, 'id' => $invocation,
                        'invocation' => $invocation, 'hash' => substr($hash->value, 8), 'outcome' => $result->accepted() ? 'success' : 'unknown', 'result' => $payload->value]);
            } elseif (!$result->accepted()) {
                throw new ContractViolation(ErrorCode::PolicyConflict);
            }
            $usageHash = $this->environment->hasher->hash($plan->runId->tenant, 'invocation-usage', [
                $invocation, $version, $result->usage->inputTokens, $result->usage->outputTokens,
                $result->usage->cacheReadTokens, $result->usage->cacheWriteTokens, $result->accepted(), $result->requestCount]);
            $db->execute('INSERT INTO ' . $db->table('usage_versions') . '(tenant_id,id,invocation_id,usage_version,payload_hash,input_tokens,output_tokens)
                VALUES (:tenant,:id,:invocation,:version,:hash,:input,:output)', ['tenant' => $tenant, 'id' => $usageId,
                    'invocation' => $invocation, 'version' => $version, 'hash' => substr($usageHash->value, 8),
                    'input' => $result->usage->inputTokens, 'output' => $result->usage->outputTokens]);
            $db->execute('INSERT INTO ' . $db->table('outbox') . '(tenant_id,id,event_key,payload_hash,event_type,aggregate_ref,deadline_at,retention_until)
                VALUES (:tenant,:id,:event,:hash,\'invocation.usage\',:aggregate,clock_timestamp()+interval \'1 day\',clock_timestamp()+interval \'8 days\')', [
                    'tenant' => $tenant, 'id' => 'outbox:' . $usageId, 'event' => 'usage:' . $usageId,
                    'hash' => substr($usageHash->value, 8), 'aggregate' => $usageId]);
            foreach ($result->proposals as $proposal) {
                $db->execute('INSERT INTO ' . $db->table('proposal_map') . '(tenant_id,id,run_id,invocation_id,proposal_key,payload_hash,proposal_ref,action_ref)
                    VALUES (:tenant,:id,:run,:invocation,:key,:hash,:payload,:action)', ['tenant' => $tenant, 'id' => $proposal->id->value,
                        'run' => $plan->runId->value, 'invocation' => $invocation, 'key' => $proposal->providerId,
                        'hash' => substr($proposal->inputHash->value, 8), 'payload' => $payload->value, 'action' => $proposal->actionId->value]);
            }
            if ($data['stage'] === 'unknown') { $data['unknown_evidence'] = $data['result_ref']; $data['resolution_evidence'] = $resolutionEvidence?->value; }
            $data['stage'] = $result->accepted() ? ($bounded ? 'bounded' : 'response') : 'unknown';
            $data['result_ref'] = $payload->value; $data['result_hash'] = $hash->value; $data['usage_version'] = $version;
            $data['usage'] = ['input_tokens' => $result->usage->inputTokens, 'output_tokens' => $result->usage->outputTokens,
                'cache_read_tokens' => $result->usage->cacheReadTokens, 'cache_write_tokens' => $result->usage->cacheWriteTokens];
            $data['tool_count'] = count($result->proposals); $data['request_count'] = $result->requestCount;
            if (!$result->accepted()) { $data['errors'] = $this->integer($data, 'errors') + 1; $data['reservation_status'] = 'unknown'; }
            return $data;
        }, $result->accepted() ? 'running' : 'waiting_external', !$result->accepted());
    }

    public function submitted(ExecutionContext $context, InvocationPlan $plan, InvocationCheckpoint $saved, SavedProposal $proposal): InvocationCheckpoint
    {
        return $this->mutate($context, $plan, $saved, ['response'], function (array $data) use ($plan, $proposal): array {
            $row = $this->environment->db->row('SELECT 1 FROM ' . $this->environment->db->table('proposal_map') . '
                WHERE tenant_id=:tenant AND id=:id AND invocation_id=:invocation AND action_ref=:action AND payload_hash=:hash', [
                    'tenant' => $plan->runId->tenant->value, 'id' => $proposal->id->value, 'invocation' => $plan->request->invocationId->value,
                    'action' => $proposal->actionId->value, 'hash' => substr($proposal->inputHash->value, 8)]);
            if ($row === null || !is_array($data['submitted'])) { throw new IntegrityConflict('Missing saved Action mapping'); }
            if (!in_array($proposal->id->value, $data['submitted'], true)) { $data['submitted'][] = $proposal->id->value; }
            return $data;
        });
    }

    public function finish(ExecutionContext $context, InvocationPlan $plan, InvocationCheckpoint $saved): InvocationCheckpoint
    {
        $this->authorize($context, $saved->lease, $plan);
        $response = new Ref($plan->runId->tenant,
            $this->environment->hasher->hash($plan->runId->tenant, 'run-response', [$plan->runId->toArray(), 1])->value);
        $publication = null;
        if ($this->publisher !== null && ($saved->data['tool_count'] ?? null) === 0) {
            // The publisher may call owners and encrypted IO. No SQL lock is held here.
            $publication = $this->publisher->prepare($context, $plan, $saved, $response);
            $plan->runId->tenant->assertSame($publication->payload->tenant);
            $this->authorize($context, $saved->lease, $plan);
        }
        $db = $this->environment->db;
        return $db->transaction(function () use ($db, $context, $plan, $saved, $response, $publication): InvocationCheckpoint {
            $this->lock($context, $saved->lease);
            $data = $this->current($plan, $saved, ['response']);
            if (!is_array($data['submitted']) || count($data['submitted']) !== $this->integer($data, 'tool_count')) {
                throw new ContractViolation(ErrorCode::PolicyConflict);
            }
            if ($data['tool_count'] !== 0) {
                $data['stage'] = 'waiting_action';
                return $this->write($saved->lease, $plan, $data, 'waiting_action', true);
            }
            if ($publication === null) {
                $delivery = (new \AgenToolsPlatform\Core\Common\IdentityKeys($this->environment->hasher))->delivery($plan->runId, 1, $plan->destination)->value;
                $hash = $this->text($data, 'result_hash'); $payload = $this->text($data, 'result_ref');
            } else {
                $delivery = $publication->deliveryKey; $hash = $publication->hash->value; $payload = $publication->payload->value;
                $registered = $db->row('SELECT content_hash FROM '.$db->table('payload_refs').'
                    WHERE tenant_id=:tenant AND id=:id AND session_id=:session
                    AND purged_at IS NULL AND purge_pending_at IS NULL AND retention_until>clock_timestamp()',
                    ['tenant' => $plan->runId->tenant->value, 'id' => $payload, 'session' => $context->session->value]);
                if ($registered === null || Connection::text($registered, 'content_hash') !== substr($hash, 8)) {
                    throw new ContractViolation(ErrorCode::AccessDenied);
                }
            }
            $db->execute('INSERT INTO ' . $db->table('responses') . '(tenant_id,id,run_id,response_key,payload_hash,payload_ref_id)
                VALUES (:tenant,:id,:run,:key,:hash,:payload)', ['tenant' => $plan->runId->tenant->value, 'id' => $response->value,
                    'run' => $plan->runId->value, 'key' => '1',
                    'hash' => substr($hash, 8), 'payload' => $payload]);
            $db->execute('INSERT INTO ' . $db->table('delivery_intents') . '(tenant_id,id,run_id,response_id,destination,delivery_key,payload_hash)
                VALUES (:tenant,:id,:run,:response,:destination,:key,:hash)', ['tenant' => $plan->runId->tenant->value,
                    'id' => $delivery, 'run' => $plan->runId->value, 'response' => $response->value, 'destination' => $plan->destination->value,
                    'key' => $delivery, 'hash' => substr($hash, 8)]);
            $data['stage'] = 'complete'; $data['delivery_ref'] = $delivery; $data['response_version'] = 1;
            return $this->write($saved->lease, $plan, $data, 'completed', true);
        });
    }

    public function actionsComplete(ExecutionContext $context, InvocationPlan $plan, InvocationCheckpoint $saved): InvocationCheckpoint
    {
        return $this->mutate($context, $plan, $saved, ['waiting_action'], function (array $data): array {
            $data['stage'] = 'continued'; return $data;
        }, 'running');
    }

    /**
     * @param list<string> $stages
     * @param callable(array<string, mixed>): array<string, mixed> $change
     */
    private function mutate(ExecutionContext $context, InvocationPlan $plan, InvocationCheckpoint $saved, array $stages, callable $change, string $status = 'running', bool $release = false): InvocationCheckpoint
    {
        $this->authorize($context, $saved->lease, $plan);
        return $this->environment->db->transaction(function () use ($context, $plan, $saved, $stages, $change, $status, $release): InvocationCheckpoint {
            $this->lock($context, $saved->lease);
            $data = $change($this->current($plan, $saved, $stages));
            if (($data['stage'] ?? null) === 'bounded') { $status = 'waiting_external'; $release = true; }
            return $this->write($saved->lease, $plan, $data, $status, $release);
        });
    }

    private function authorize(ExecutionContext $context, AdvanceLease $lease, InvocationPlan $plan): void
    {
        $lease->runId->assertSame($plan->runId);
        $context->session->tenant->assertSame($plan->runId->tenant);
        $this->environment->authorize($context, $plan->snapshot);
    }

    /** @return array<string, mixed> */
    private function lock(ExecutionContext $context, AdvanceLease $lease): array
    {
        $db = $this->environment->db;
        return $db->row('SELECT r.* FROM ' . $db->table('runs') . ' r WHERE r.tenant_id=:tenant AND r.id=:id
            AND r.version=:version AND r.fencing_epoch=:epoch AND r.claim_owner=:owner AND r.lease_until>clock_timestamp()
            AND r.status NOT IN (\'completed\',\'failed\',\'cancelled\',\'cancelling\')
            AND EXISTS (SELECT 1 FROM ' . $db->table('turns') . ' t WHERE t.tenant_id=r.tenant_id AND t.id=r.turn_id AND t.session_id=:session)
            FOR UPDATE OF r', ['tenant' => $lease->runId->tenant->value, 'id' => $lease->runId->value,
                'version' => $lease->version, 'epoch' => $lease->epoch, 'owner' => $lease->owner, 'session' => $context->session->value])
            ?? throw new ContractViolation(ErrorCode::StaleClaim);
    }

    /**
     * @param list<string> $stages
     * @return array<string, mixed>
     */
    private function current(InvocationPlan $plan, InvocationCheckpoint $saved, array $stages): array
    {
        $row = $this->environment->db->row('SELECT state_ref FROM ' . $this->environment->db->table('run_steps') . '
            WHERE tenant_id=:tenant AND run_id=:run AND step_key=:step FOR UPDATE', $this->key($plan)) ?? throw new StorageFailure();
        $data = $this->decode(Connection::text($row, 'state_ref'));
        // Compare canonical content, since jsonb/encoding may reorder object keys.
        $hasher = $this->environment->hasher;
        $hasher->hash($plan->runId->tenant, 'saved-invocation', $data)->assertSame($hasher->hash($plan->runId->tenant, 'saved-invocation', $saved->data));
        if (!in_array($this->text($data, 'stage'), $stages, true) || $this->text($data, 'invocation_id') !== $plan->request->invocationId->value) {
            throw new ContractViolation(ErrorCode::PolicyConflict);
        }
        return $data;
    }

    /** @param array<string, mixed> $data */
    private function write(AdvanceLease $lease, InvocationPlan $plan, array $data, string $status, bool $release = false): InvocationCheckpoint
    {
        $db = $this->environment->db;
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        if (strlen($json) > 16384) { throw new ContractViolation(ErrorCode::InvalidInput); }
        $db->execute('UPDATE ' . $db->table('run_steps') . ' SET version=version+1,state_ref=:state
            WHERE tenant_id=:tenant AND run_id=:run AND step_key=:step', $this->key($plan) + ['state' => $json]);
        $db->execute('UPDATE ' . $db->table('runs') . ' SET version=version+1,status=:status,
            terminal_code=:terminal,claim_owner=CASE WHEN :release_owner=1 THEN NULL ELSE claim_owner END,
            lease_until=CASE WHEN :release_lease=1 THEN NULL ELSE lease_until END WHERE tenant_id=:tenant AND id=:id', [
                'tenant' => $lease->runId->tenant->value, 'id' => $lease->runId->value, 'status' => $status,
                'terminal' => $status === 'completed' ? 'response_saved' : null, 'release_owner' => $release ? 1 : 0, 'release_lease' => $release ? 1 : 0]);
        $data = $this->decode($json);
        return new InvocationCheckpoint(new AdvanceLease($lease->runId, $lease->version + 1, $lease->epoch, $lease->owner), $data);
    }

    /** @return array{tenant: string, run: string, step: string} */
    private function key(InvocationPlan $plan): array { return ['tenant' => $plan->runId->tenant->value, 'run' => $plan->runId->value, 'step' => $plan->stepKey]; }
    private function stepId(InvocationPlan $plan): string { return $this->environment->hasher->hash($plan->runId->tenant, 'run-step', [$plan->runId->value, $plan->stepKey])->value; }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_is_list($data)) { throw new StorageFailure(); }
        return $this->named($data);
    }

    /** @return array<string, mixed> */
    private function named(mixed $data): array
    {
        if (!is_array($data)) { throw new StorageFailure(); }
        $named = [];
        foreach ($data as $key => $value) {
            if (!is_string($key)) { throw new StorageFailure(); }
            $named[$key] = $value;
        }
        return $named;
    }

    /** @param array<string, mixed> $data */
    private function integer(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value) || $value < 0) { throw new StorageFailure(); } return $value;
    }
    /** @param array<string, mixed> $data */
    private function text(array $data, string $key): string { return Connection::text($data, $key); }
    private function totalTools(InvocationPlan $plan): int
    {
        $rows = $this->environment->db->execute('SELECT state_ref FROM ' . $this->environment->db->table('run_steps') . '
            WHERE tenant_id=:tenant AND run_id=:run', ['tenant' => $plan->runId->tenant->value, 'run' => $plan->runId->value])->fetchAll(\PDO::FETCH_ASSOC);
        $total = 0;
        foreach ($rows as $row) { $total += $this->integer($this->decode(Connection::text($this->named($row), 'state_ref')), 'tool_count'); }
        return $total;
    }
}
