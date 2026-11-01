<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Effects;

use AgenTools\Action\FoldedAction;
use AgenTools\Schema\Canonicalizer;
use AgenToolsPlatform\Core\Actions\{ActionDefinition, ActionPolicy, ActionRegistry, ActionSubmission};
use AgenToolsPlatform\Core\Actions\Confirmation\ConfirmedAction;
use AgenToolsPlatform\Core\Actions\Effects\{LookupResult, LookupStatus, ManualEvidence, Request, Strategy};
use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, IdentityKeys, IntegrityConflict, PayloadHash, Ref};
use AgenToolsPlatform\Postgres\Confirmation\Journal;
use AgenToolsPlatform\Postgres\Runtime\{Connection, RepositoryContext};

/** One transaction per checkpoint. No owner IO is performed under the local lock. */
final readonly class Ledger
{
    public int $epoch;

    public function __construct(private RepositoryContext $environment, private ConfirmedAction $grant,
        private ActionSubmission $submission, private ActionRegistry $registry, private ActionPolicy $policy, ?int $epoch = null)
    {
        $grant->action->tenant->assertSame($submission->request->context->identity->tenant);
        $this->epoch = $epoch ?? $grant->epoch + 1;
    }

    public function prepare(string $namespace, Strategy $strategy): Request
    {
        $definition=$this->authorize();
        $db = $this->environment->db;
        return $db->transaction(function () use ($db, $namespace, $strategy, $definition): Request {
            $journal = new Journal($this->environment);
            $state = $journal->read($this->grant->action, true);
            $this->proof();
            $advertised=match ($definition->idempotencyCapability) {
                'native_key'=>Strategy::NativeKey, 'read_before_write'=>Strategy::ReadBeforeWrite,
                default=>Strategy::Unsupported,
            };
            if ($strategy !== $advertised) { throw new IntegrityConflict('Owner capability conflicts with registered definition'); }
            $fresh=$db->row('SELECT p.id FROM '.$db->table('confirmation_previews').' p JOIN '.$db->table('action_submissions').' s
                ON s.tenant_id=p.tenant_id AND s.id=p.action_ref WHERE p.tenant_id=:tenant AND p.action_ref=:action
                AND (p.binding->>\'expires_at\')::timestamptz>clock_timestamp() AND s.deadline_at>clock_timestamp()', $this->keys());
            if ($fresh === null) { throw new ContractViolation(ErrorCode::DeadlineExceeded); }
            if ($state->seq !== $this->grant->version || $state->epoch !== $this->grant->epoch
                || $this->epoch !== $state->epoch + 1 || $state->cancellationRequested || $state->status?->value !== 'running') {
                throw new ContractViolation(ErrorCode::StaleClaim);
            }
            $id = $this->grant->action;
            $effect = (new IdentityKeys($this->environment->hasher))->effectKey($id, 0)->value;
            $request = new Request($id, $effect, $namespace, 'owner:'.substr($effect, 8),
                $this->submission->request->inputHash, $this->submission->request->inputRef,
                $this->submission->request->context, $this->epoch, $strategy);
            $db->execute('INSERT INTO '.$db->table('effect_intents').'
                (tenant_id,id,action_ref,effect_key,owner_namespace,owner_operation_key,request_hash,request_ref,request_context,claim_epoch,retry_strategy,retry_after)
                VALUES (:tenant,:id,:action,:effect,:namespace,:operation,:hash,:ref,CAST(:context AS jsonb),:epoch,:strategy,
                    clock_timestamp()+make_interval(secs => CAST(:backoff AS double precision)/1000))',
                $this->keys() + ['id'=>'intent:'.$effect,'effect'=>$effect,'namespace'=>$namespace,'operation'=>$request->operationKey,
                    'hash'=>substr($request->requestHash->value,8),'ref'=>$request->requestRef->value,
                    'context'=>json_encode($request->context->toArray(),JSON_THROW_ON_ERROR),'epoch'=>$request->requestedEpoch,'strategy'=>$strategy->value,
                    'backoff'=>max(1000,$definition->retryBackoffMs)]);
            $journal->append($id,$state,[['kind'=>'epoch_started','payload'=>[],'epoch'=>$this->epoch],
                ['kind'=>'effect_requested','payload'=>['effect_id'=>$effect,'idempotency_key'=>$request->operationKey,
                    'owner_namespace'=>$namespace,'request_hash'=>substr($request->requestHash->value,8),'request_ref'=>$request->requestRef->value,
                    'retry_strategy'=>$strategy->value],'epoch'=>$this->epoch]]);
            return $request;
        });
    }

    public function request(): Request
    {
        $this->authorize();
        return $this->environment->db->transaction(function (): Request {
            [, $row] = $this->locked();
            return $this->restore($row);
        });
    }

    public function accepted(): ?LookupResult
    {
        $this->authorize();
        return $this->environment->db->transaction(function (): ?LookupResult {
            [$state, $row] = $this->locked();
            $outcome = Connection::text($row, 'outcome');
            if (!in_array($outcome, ['success','failed'], true)) { return null; }
            $request = $this->restore($row);
            $effect = $state->effects[$request->effectKey] ?? throw new IntegrityConflict('Missing accepted effect');
            $receipt = Connection::text($row, 'receipt_ref');
            if ($effect->externalRef !== $receipt || $effect->status->value !== ($outcome === 'success' ? 'confirmed' : 'failed')) {
                throw new IntegrityConflict('Accepted effect projection conflicts with journal');
            }
            return new LookupResult($outcome === 'success' ? LookupStatus::Succeeded : LookupStatus::Failed,
                new Ref($request->action->tenant,$request->operationKey),$request->ownerNamespace,$request->requestHash,
                new Ref($request->action->tenant,$receipt));
        });
    }

    /** @param (\Closure():void)|null $audit Committed atomically with a manual outcome, on this connection. */
    public function accept(LookupResult $result, ?ManualEvidence $manual = null, ?int $expectedVersion = null,
        ?Ref $operation = null, string $reason = 'owner_evidence', ?\Closure $audit = null): void
    {
        $this->authorize();
        $db = $this->environment->db;
        $db->transaction(function () use ($db,$result,$manual,$expectedVersion,$operation,$reason,$audit): void {
            [$state,$row] = $this->locked();
            $request = $this->restore($row);
            $result->assertRequest($request);
            if ($manual!==null) {
                if ($expectedVersion===null || $state->seq!==$expectedVersion) { throw new ContractViolation(ErrorCode::OptimisticConflict); }
                if (preg_match('/^[a-z][a-z0-9_.:-]{0,127}$/D',$reason)!==1) { throw new \InvalidArgumentException('Safe resolution reason required'); }
                if ($operation!==null) { $request->action->tenant->assertSame($operation->tenant); }
            }
            if (!in_array($result->status,[LookupStatus::Succeeded,LookupStatus::Failed],true) || $result->receipt === null) {
                throw new \InvalidArgumentException('Only proven known outcomes can be accepted');
            }
            $outcome = $result->status === LookupStatus::Succeeded ? 'success' : 'failed';
            if (in_array(Connection::text($row,'outcome'),['success','failed'],true)) {
                if ($manual!==null) { throw new ContractViolation(ErrorCode::PolicyConflict); }
                if (Connection::text($row,'outcome') !== $outcome || $row['receipt_ref'] !== $result->receipt->value) {
                    throw new IntegrityConflict('Accepted effect outcome is immutable');
                }
                return;
            }
            $specs = [];
            if ($manual !== null) {
                $manual->outcome->assertRequest($request);
                if ($manual->outcome !== $result) { throw new IntegrityConflict('Manual outcome evidence conflict'); }
                $specs[] = ['kind'=>'platform.effect.manual_resolution','payload'=>['effect_id'=>$request->effectKey,
                    'operator_ref'=>$manual->operator->value,'evidence_ref'=>$manual->evidence->value,'known_outcome_basis'=>$manual->basis,
                    'outcome'=>$result->status->value,'receipt_ref'=>$result->receipt->value,'request_hash'=>$request->requestHash->value,
                    'reason'=>$reason,'operation_ref'=>$operation?->value,'expected_version'=>$expectedVersion], 'epoch'=>$this->epoch];
            }
            $specs[] = ['kind'=>$outcome === 'success' ? 'effect_confirmed' : 'effect_failed',
                'payload'=>['effect_id'=>$request->effectKey,'external_ref'=>$result->receipt->value,
                    'request_hash'=>$request->requestHash->value,'owner_operation_key'=>$request->operationKey], 'epoch'=>$this->epoch];
            $specs[] = ['kind'=>$outcome === 'success' ? 'action_completed' : 'action_failed','payload'=>[]];
            (new Journal($this->environment))->append($request->action,$state,$specs);
            $updated = $db->execute('UPDATE '.$db->table('effect_intents').' SET version=version+1,outcome=:outcome,receipt_ref=:receipt
                WHERE tenant_id=:tenant AND action_ref=:action AND outcome IN (\'pending\',\'unknown\')',
                $this->keys()+['outcome'=>$outcome,'receipt'=>$result->receipt->value]);
            if ($updated->rowCount() !== 1) { throw new IntegrityConflict('One unresolved effect is required'); }
            if ($audit!==null) { $audit(); }
        });
    }

    public function unknown(string $reason): void
    {
        $this->authorize();
        $db = $this->environment->db;
        $db->transaction(function () use ($db,$reason): void {
            [$state,$row] = $this->locked();
            if (in_array(Connection::text($row,'outcome'),['success','failed'],true)) { return; }
            if (Connection::text($row,'outcome') === 'unknown' && $state->status?->value === 'reconciliation_required') { return; }
            $specs = [['kind'=>'platform.effect.unresolved','payload'=>['reason'=>$reason,
                'effect_id'=>Connection::text($row,'effect_key'),'reservation_disposition'=>'retained'], 'epoch'=>$this->epoch]];
            if ($state->status?->value === 'applying') { $specs[] = ['kind'=>'task_binding_lost','payload'=>[]]; }
            (new Journal($this->environment))->append($this->grant->action,$state,$specs);
            $db->execute('UPDATE '.$db->table('effect_intents').' SET version=version+1,outcome=\'unknown\'
                WHERE tenant_id=:tenant AND action_ref=:action AND outcome IN (\'pending\',\'unknown\')', $this->keys());
        });
    }

    /** Atomic bounded permission to attempt the SAME owner operation; lease expiry grants nothing. */
    public function reserveRetry(LookupResult $lookup): Request
    {
        $definition=$this->authorize();
        $db = $this->environment->db;
        return $db->transaction(function () use ($db,$lookup,$definition): Request {
            [$state,$row] = $this->locked();
            $request = $this->restore($row);
            $lookup->assertRequest($request);
            if (!$request->strategy->takeoverDecision($lookup->status) || $state->cancellationRequested
                || !in_array(Connection::text($row,'outcome'),['pending','unknown'],true)) {
                throw new ContractViolation(ErrorCode::ManualResolutionRequired);
            }
            $updated = $db->execute('UPDATE '.$db->table('effect_intents').' SET version=version+1,attempt_count=attempt_count+1,
                retry_after=clock_timestamp()+make_interval(secs => CAST(:backoff AS double precision)/1000)
                WHERE tenant_id=:tenant AND action_ref=:action AND attempt_count<:max_attempts AND retry_after<=clock_timestamp()
                AND created_at+make_interval(secs => CAST(:deadline AS double precision)/1000)>clock_timestamp()
                AND EXISTS (SELECT 1 FROM '.$db->table('confirmation_previews').' p JOIN '.$db->table('action_submissions').' s
                    ON s.tenant_id=p.tenant_id AND s.id=p.action_ref WHERE p.tenant_id=:ptenant AND p.action_ref=:paction
                    AND (p.binding->>\'expires_at\')::timestamptz>clock_timestamp() AND s.deadline_at>clock_timestamp())',
                $this->keys()+['ptenant'=>$request->action->tenant->value,'paction'=>$request->action->value,
                    'max_attempts'=>min(3,$definition->maxAttempts),'backoff'=>max(1000,$definition->retryBackoffMs),'deadline'=>$definition->deadlineMs]);
            if ($updated->rowCount() !== 1) { throw new ContractViolation(ErrorCode::ManualResolutionRequired); }
            (new Journal($this->environment))->append($request->action,$state,[['kind'=>'platform.effect.retry',
                'payload'=>['effect_id'=>$request->effectKey,'owner_operation_key'=>$request->operationKey,
                    'request_hash'=>$request->requestHash->value,'lookup'=>$lookup->status->value],'epoch'=>$this->epoch]]);
            return $request;
        });
    }

    /** Takeover changes only the local fence; immutable original request and owner key survive. */
    public function takeover(): self
    {
        $this->authorize();
        $this->environment->db->transaction(function (): void {
            [$state,$row] = $this->locked();
            if (!in_array(Connection::text($row,'outcome'),['pending','unknown'],true)) { throw new ContractViolation(ErrorCode::StaleClaim); }
            (new Journal($this->environment))->append($this->grant->action,$state,
                [['kind'=>'epoch_started','payload'=>[],'epoch'=>$this->epoch+1]]);
        });
        return new self($this->environment,$this->grant,$this->submission,$this->registry,$this->policy,$this->epoch+1);
    }

    private function authorize(): ActionDefinition
    {
        $context=$this->submission->request->context;
        $this->environment->authorize($context);
        return $this->registry->executionView($this->submission->request->definition,$this->policy->current($context))->definition;
    }

    /** @return array{tenant:string,action:string} */
    private function keys(): array { return ['tenant'=>$this->grant->action->tenant->value,'action'=>$this->grant->action->value]; }

    private function proof(): void
    {
        $db = $this->environment->db;
        $proof = $db->row('SELECT p.request_hash,e.payload_json FROM '.$db->table('confirmation_previews').' p JOIN '.$db->table('agentools_events').' e
            ON e.tenant_id=p.tenant_id AND e.action_id=p.action_ref AND e.seq=:version
            WHERE p.tenant_id=:tenant AND p.action_ref=:action AND p.id=:proposal AND e.kind=\'confirmation_resolved\'',
            $this->keys()+['version'=>$this->grant->version,'proposal'=>$this->grant->proposal->value]);
        if ($proof === null || Connection::text($proof,'request_hash') !== $this->submission->request->requestHash->value) {
            throw new ContractViolation(ErrorCode::AccessDenied);
        }
        /** @var array<string,mixed> $payload */
        $payload = json_decode(Connection::text($proof,'payload_json'),true,512,JSON_THROW_ON_ERROR);
        if (($payload['binding_hash']??null) !== $this->grant->bindingHash->value || ($payload['decision']??null) !== 'approve') {
            throw new ContractViolation(ErrorCode::StaleClaim);
        }
    }

    /** @return array{FoldedAction,array<string,mixed>} */
    private function locked(): array
    {
        $state = (new Journal($this->environment))->read($this->grant->action,true);
        $this->proof();
        if ($state->epoch !== $this->epoch) { throw new ContractViolation(ErrorCode::StaleClaim); }
        $db = $this->environment->db;
        $row = $db->row('SELECT * FROM '.$db->table('effect_intents').' WHERE tenant_id=:tenant AND action_ref=:action FOR UPDATE', $this->keys());
        if ($row === null) { throw new IntegrityConflict('Missing committed effect request'); }
        $request = $this->restore($row);
        $effect = $state->effects[$request->effectKey] ?? null;
        if ($effect === null || $effect->idempotencyKey !== $request->operationKey
            || $effect->epoch !== ($effect->status === \AgenTools\Action\EffectStatus::Requested ? $request->requestedEpoch : $this->epoch)
            || $request->requestHash->value !== $this->submission->request->inputHash->value
            || $request->requestRef->value !== $this->submission->request->inputRef->value
            || (new Canonicalizer())->canonicalizeJson(Connection::text($row,'request_context')) !== (new Canonicalizer())->canonicalize($this->submission->request->context->toArray())) {
            throw new IntegrityConflict('Immutable effect request conflict');
        }
        return [$state,$row];
    }

    /** @param array<string,mixed> $row */
    private function restore(array $row): Request
    {
        return new Request($this->grant->action,Connection::text($row,'effect_key'),Connection::text($row,'owner_namespace'),
            Connection::text($row,'owner_operation_key'),new PayloadHash('sha-256:'.Connection::text($row,'request_hash')),
            new Ref($this->grant->action->tenant,Connection::text($row,'request_ref')),$this->submission->request->context,
            Connection::number($row,'claim_epoch'),Strategy::from(Connection::text($row,'retry_strategy')));
    }
}
