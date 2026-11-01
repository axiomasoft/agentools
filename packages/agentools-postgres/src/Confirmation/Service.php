<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Confirmation;

use AgenTools\Confirmation\{ConfirmationProtocol, ConfirmationRecord, ConfirmationService, ConfirmationToken, TokenEnvelope, TokenKeyProvider};
use AgenTools\Contracts\{Clock, ConfirmationTokenStore};
use AgenTools\Schema\Canonicalizer;
use AgenToolsPlatform\Core\Actions\{ActionApplication, ActionPolicy, ActionRegistry, ActionSubmission};
use AgenToolsPlatform\Core\Actions\Confirmation\{Binding, ConfirmationAuthority, ConfirmationGateway, ConfirmedAction, Preview};
use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, IntegrityConflict, PayloadHash, Ref, UtcInstant};
use AgenToolsPlatform\Postgres\Runtime\{Connection, RepositoryContext};

/** A headless prepare/approve/commit adapter. It grants no external IO capability. */
final readonly class Service implements ConfirmationGateway
{
    private const ISSUER='agentools-platform';
    private const AUDIENCE='bound-action';

    public function __construct(private RepositoryContext $environment, private ActionApplication $actions,
        private ActionRegistry $registry, private ActionPolicy $policy, private ConfirmationAuthority $authority,
        private TokenKeyProvider $keys, private ConfirmationTokenStore $replay, private Sealer $sealer,
        private Clock $clock, private int $ttlSeconds=300)
    {
        if ($ttlSeconds<1 || $ttlSeconds>3600) { throw new \InvalidArgumentException('Bounded confirmation TTL required'); }
    }

    public function prepare(#[\SensitiveParameter] ActionSubmission $submission): Preview
    {
        return $this->boundary(fn (): Preview=>$this->prepareBound($submission));
    }

    public function approve(#[\SensitiveParameter] string $token,#[\SensitiveParameter] ActionSubmission $submission): void
    {
        $this->boundary(function () use ($token,$submission): null { $this->approveBound($token,$submission); return null; });
    }

    public function commit(#[\SensitiveParameter] string $token,#[\SensitiveParameter] ActionSubmission $submission): ConfirmedAction
    {
        return $this->boundary(fn (): ConfirmedAction=>$this->commitBound($token,$submission));
    }

    /**
     * SDK errors retain their codes without nested frames carrying token/input arguments.
     * @template T
     * @param callable():T $operation
     * @return T
     */
    private function boundary(#[\SensitiveParameter] callable $operation): mixed
    {
        try { return $operation(); }
        catch (\AgenTools\Confirmation\ConfirmationFailure $error) { throw new \AgenTools\Confirmation\ConfirmationFailure($error->error); }
        catch (\AgenTools\Error\ToolExecutionException $error) { throw new \AgenTools\Error\ToolExecutionException($error->error); }
        catch (ContractViolation $error) { throw new ContractViolation($error->errorCode); }
        catch (IntegrityConflict) { throw new IntegrityConflict('Confirmation binding integrity conflict'); }
        catch (\InvalidArgumentException) { throw new ContractViolation(ErrorCode::InvalidInput); }
        catch (\Throwable) { throw new \AgenToolsPlatform\Postgres\Runtime\StorageFailure(); }
    }

    private function prepareBound(ActionSubmission $submission): Preview
    {
        $id=$this->actions->submit($submission)->view->receipt->actionId;
        $db=$this->environment->db;
        $saved=$db->row('SELECT * FROM '.$db->table('confirmation_previews').' WHERE tenant_id=:tenant AND action_ref=:action',
            ['tenant'=>$id->tenant->value,'action'=>$id->value]);
        if ($saved!==null) { return $this->restore($saved,$submission); }
        $state=$db->transaction(fn ()=>(new Journal($this->environment))->read($id,true));
        if ($state->status?->value!=='created' || $state->cancellationRequested) { throw new ContractViolation(ErrorCode::PolicyConflict); }
        $presentation=(new Canonicalizer())->canonicalizeJson($this->authority->presentation($submission));
        // at1 uses whole-second UTC claims. Round down explicitly before constructing any binding.
        $sample=$this->clock->now();
        $now=$sample->setTimestamp($sample->getTimestamp());
        $deadline=new \DateTimeImmutable($submission->request->deadline->value);
        $expiry=min($now->getTimestamp()+$this->ttlSeconds,$deadline->getTimestamp());
        if ($expiry<=$now->getTimestamp()) { throw new ContractViolation(ErrorCode::DeadlineExceeded); }
        $expires=new UtcInstant(gmdate('Y-m-d\TH:i:s\Z',$expiry));
        $binding=new Binding($id,$submission,$state->seq+2,$state->epoch,$expires,
            $this->environment->hasher->hash($id->tenant,'confirmation-presentation',json_decode($presentation,false,512,JSON_THROW_ON_ERROR)),$this->environment->hasher);
        $ports=$this->ports($binding,$submission,$expiry-$now->getTimestamp());
        $snapshotClock=new class($now) implements Clock {
            public function __construct(private \DateTimeImmutable $instant) {}
            public function now(): \DateTimeImmutable { return $this->instant; }
        };
        /** @var array<string,mixed> $preview */
        $preview=json_decode($presentation,true,512,JSON_THROW_ON_ERROR);
        $token=$this->hardened($ports,$snapshotClock)->prepare($id->value,BoundPorts::TOOL,
            json_decode($submission->inputJson,false,512,JSON_THROW_ON_ERROR),$preview);
        if (!$ports->record instanceof ConfirmationRecord) { throw new IntegrityConflict('Missing hardened record'); }
        $sealed=$this->sealer->seal(json_encode(['token'=>$token->value,'presentation'=>$presentation],JSON_THROW_ON_ERROR));
        $this->environment->authorize($submission->request->context);
        $saved=$db->transaction(function () use ($db,$id,$binding,$submission,$token,$sealed): array {
            $journal=new Journal($this->environment);
            $current=$journal->read($id,true);
            $winner=$db->row('SELECT * FROM '.$db->table('confirmation_previews').' WHERE tenant_id=:tenant AND action_ref=:action',
                ['tenant'=>$id->tenant->value,'action'=>$id->value]);
            if ($winner!==null) { return $winner; }
            if ($current->seq+2!==$binding->version || $current->epoch!==$binding->epoch || $current->cancellationRequested
                || $current->status?->value!=='created') { throw new ContractViolation(ErrorCode::OptimisticConflict); }
            $row=$db->row('INSERT INTO '.$db->table('confirmation_previews').'
                (tenant_id,id,action_ref,request_hash,binding_hash,binding,token_digest,sealed)
                VALUES (:tenant,:id,:action,:request,:hash,CAST(:binding AS jsonb),:token,:sealed) RETURNING *',
                ['tenant'=>$id->tenant->value,'id'=>$token->proposalId,'action'=>$id->value,'request'=>$submission->request->requestHash->value,
                    'hash'=>$binding->hash->value,'binding'=>json_encode($binding->toArray(),JSON_THROW_ON_ERROR),
                    'token'=>ConfirmationRecord::digestToken($token->value),'sealed'=>$sealed]) ?? throw new IntegrityConflict('Missing prepared mapping');
            $journal->append($id,$current,[['kind'=>'action_started','payload'=>[]],['kind'=>'confirmation_requested','payload'=>[
                'proposal_id'=>$token->proposalId,'proposal_version'=>1,'deadline'=>$binding->expiresAt->value,
                'binding_hash'=>$binding->hash->value]]]);
            return $row;
        });
        return $this->restore($saved,$submission);
    }

    private function approveBound(#[\SensitiveParameter] string $token,ActionSubmission $submission): void
    {
        $preview=$this->lookup($token,$submission);
        $decision=$this->authority->decision($preview);
        $context=$submission->request->context;
        if ($decision===null || $decision->source->tenant->value!==$context->identity->tenant->value
            || $decision->actor->value!==$context->identity->actorRef->value
            || $decision->bindingHash->value!==$preview->binding->hash->value
            || new \DateTimeImmutable($decision->expiresAt->value)<=$this->clock->now()
            || new \DateTimeImmutable($decision->expiresAt->value)>new \DateTimeImmutable($preview->binding->expiresAt->value)) {
            throw new ContractViolation(ErrorCode::ConfirmationInvalid);
        }
        $this->environment->authorize($context);
        $db=$this->environment->db;
        $db->transaction(function () use ($db,$preview,$decision): void {
            $binding=$preview->binding;
            $journal=new Journal($this->environment);
            $state=$journal->read($binding->action,true);
            $saved=$db->row('SELECT * FROM '.$db->table('confirmation_proofs').' WHERE tenant_id=:tenant AND id=:id',
                ['tenant'=>$preview->proposal->tenant->value,'id'=>$preview->proposal->value]);
            if ($saved!==null) {
                if (Connection::text($saved,'source_ref')!==$decision->source->value || Connection::text($saved,'source_hash')!==$decision->sourceHash->value
                    || Connection::text($saved,'binding_hash')!==$binding->hash->value || Connection::text($saved,'channel')!==$decision->channel
                    || new \DateTimeImmutable(Connection::text($saved,'expires_at'))!=new \DateTimeImmutable($decision->expiresAt->value)) { throw new IntegrityConflict('Confirmation source event conflict'); }
                return;
            }
            $this->assertPending($preview,$state->seq,$state->epoch,$state->status?->value,$state->cancellationRequested,0);
            $db->execute('INSERT INTO '.$db->table('confirmation_proofs').'
                (tenant_id,id,source_ref,source_hash,binding_hash,actor_ref,channel,expires_at,journal_version)
                VALUES (:tenant,:id,:source,:source_hash,:binding,:actor,:channel,:expires,:version)',
                ['tenant'=>$preview->proposal->tenant->value,'id'=>$preview->proposal->value,'source'=>$decision->source->value,
                    'source_hash'=>$decision->sourceHash->value,'binding'=>$binding->hash->value,'actor'=>$decision->actor->value,
                    'channel'=>$decision->channel,'expires'=>$decision->expiresAt->value,'version'=>$state->seq+1]);
            $journal->append($binding->action,$state,[['kind'=>'platform.confirmation.proof','payload'=>[
                'proposal_id'=>$preview->proposal->value,'binding_hash'=>$binding->hash->value,'source_ref'=>$decision->source->value,
                'source_hash'=>$decision->sourceHash->value,'channel'=>$decision->channel,
                'actor_ref'=>$decision->actor->value,'expires_at'=>$decision->expiresAt->value]]]);
        });
        $this->environment->authorize($context);
    }

    private function commitBound(#[\SensitiveParameter] string $token,ActionSubmission $submission): ConfirmedAction
    {
        $preview=$this->lookup($token,$submission);
        $binding=$preview->binding;
        $ports=$this->ports($binding,$submission,$this->ttlSeconds);
        $parsed=ConfirmationToken::fromEnvelope(TokenEnvelope::parse($token,$this->keys,$this->clock->now(),self::ISSUER,self::AUDIENCE));
        $ports->restore($parsed,$preview->presentationJson);
        // The dated service validates signature/args/descriptor/policy/proof/authorizer and durably consumes once.
        $this->hardened($ports,$this->clock)->commit($token,json_decode($submission->inputJson,false,512,JSON_THROW_ON_ERROR));
        $this->environment->authorize($submission->request->context);
        $this->registry->executionView($submission->request->definition,$this->policy->current($submission->request->context));
        return $this->environment->db->transaction(function () use ($preview,$binding): ConfirmedAction {
            $journal=new Journal($this->environment);
            $state=$journal->read($binding->action,true);
            $this->assertPending($preview,$state->seq,$state->epoch,$state->status?->value,$state->cancellationRequested,1);
            $state=$journal->append($binding->action,$state,[['kind'=>'confirmation_resolved','payload'=>[
                'decision'=>'approve','proposal_id'=>$preview->proposal->value,'binding_hash'=>$binding->hash->value]]]);
            return new ConfirmedAction($binding->action,$preview->proposal,$binding->hash,$state->seq,$state->epoch);
        });
    }

    private function lookup(string $token,ActionSubmission $submission): Preview
    {
        $this->environment->authorize($submission->request->context);
        $db=$this->environment->db;
        $row=$db->row('SELECT * FROM '.$db->table('confirmation_previews').' WHERE tenant_id=:tenant AND token_digest=:token',
            ['tenant'=>$submission->request->context->identity->tenant->value,'token'=>ConfirmationRecord::digestToken($token)]);
        if ($row===null) { throw new ContractViolation(ErrorCode::ConfirmationInvalid); }
        return $this->restore($row,$submission);
    }

    /** @param array<string,mixed> $row */
    private function restore(array $row,ActionSubmission $submission): Preview
    {
        $this->environment->authorize($submission->request->context);
        if (!hash_equals(Connection::text($row,'request_hash'),$submission->request->requestHash->value)) {
            throw new IntegrityConflict('Confirmation request binding changed; a new Action preview is required');
        }
        /** @var array{action_version:int,epoch:int,expires_at:string,preview_hash:string} $tuple */
        $tuple=json_decode(Connection::text($row,'binding'),true,512,JSON_THROW_ON_ERROR);
        $id=new \AgenToolsPlatform\Core\Common\ActionId($submission->request->context->identity->tenant,Connection::text($row,'action_ref'));
        $this->actions->status($id,$submission->request->context);
        $binding=new Binding($id,$submission,$tuple['action_version'],$tuple['epoch'],new UtcInstant($tuple['expires_at']),
            new PayloadHash($tuple['preview_hash']),$this->environment->hasher);
        if (!hash_equals(Connection::text($row,'binding_hash'),$binding->hash->value)
            || (new Canonicalizer())->canonicalize(json_decode(Connection::text($row,'binding'),false,512,JSON_THROW_ON_ERROR))
                !==(new Canonicalizer())->canonicalize($binding->toArray())) { throw new IntegrityConflict('Immutable confirmation binding conflict'); }
        /** @var array{token:string,presentation:string} $secret */
        $secret=json_decode($this->sealer->open(Connection::text($row,'sealed')),true,512,JSON_THROW_ON_ERROR);
        if (ConfirmationRecord::digestToken($secret['token'])!==Connection::text($row,'token_digest')) { throw new IntegrityConflict('Stored token conflict'); }
        $binding->previewHash->assertSame($this->environment->hasher->hash($id->tenant,'confirmation-presentation',json_decode($secret['presentation'],false,512,JSON_THROW_ON_ERROR)));
        return new Preview(new Ref($id->tenant,Connection::text($row,'id')),$binding,$secret['token'],$secret['presentation']);
    }

    private function ports(Binding $binding,ActionSubmission $submission,int $ttl): BoundPorts
    {
        $entry=$this->registry->executionView($submission->request->definition,$this->policy->current($submission->request->context));
        return new BoundPorts($this->environment,$binding,$submission,$entry->definition,$this->registry,$this->policy,$ttl);
    }

    private function hardened(BoundPorts $ports,Clock $clock): ConfirmationService
    {
        return new ConfirmationService(new ConfirmationProtocol($this->keys,$this->replay,self::ISSUER,self::AUDIENCE),$this->keys,
            $this->replay,self::ISSUER,self::AUDIENCE,$ports,$clock,$ports,$ports,$ports,$ports,$ports,$ports,new Canonicalizer());
    }

    private function assertPending(Preview $preview,int $version,int $epoch,?string $status,bool $cancelled,int $offset): void
    {
        if ($version!==$preview->binding->version+$offset || $epoch!==$preview->binding->epoch || $status!=='awaiting_confirmation' || $cancelled) {
            throw new ContractViolation(ErrorCode::StaleClaim);
        }
    }
}
