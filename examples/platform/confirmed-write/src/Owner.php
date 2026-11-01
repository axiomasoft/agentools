<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\ConfirmedWrite;

use AgenToolsPlatform\Core\Actions\ActionSubmission;
use AgenToolsPlatform\Core\Actions\Confirmation\ConfirmedAction;
use AgenToolsPlatform\Core\Actions\Effects\{LookupResult, LookupStatus, OwnerPort, Request, Strategy};
use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, IntegrityConflict, Ref};
use AgenToolsPlatform\Examples\DirectAction\Jcs;
use AgenToolsPlatform\Postgres\Effects\{Ledger, Service};
use AgenToolsPlatform\Postgres\Runtime\Connection;
use PDO;

/** Synthetic owner adapter; production hosts supply their own owner port. */
final readonly class Owner
{
    public function __construct(private Composition $composition,private PDO $ownerPdo,private string $schema) {}

    public function provision(): void
    {
        $db=$this->composition->environment->db;
        $db->execute('CREATE TABLE '.$db->table('example_owner_permissions').' (tenant_id text NOT NULL,actor_ref text NOT NULL,allowed boolean NOT NULL,PRIMARY KEY(tenant_id,actor_ref))');
        $db->execute('CREATE TABLE '.$db->table('example_owner_writes').' (tenant_id text NOT NULL,operation_key text NOT NULL,request_hash text NOT NULL,payload jsonb NOT NULL,PRIMARY KEY(tenant_id,operation_key))');
        $db->execute('INSERT INTO '.$db->table('example_owner_permissions').' VALUES (:tenant,:actor,true)',
            ['tenant'=>$this->composition->host->tenant->value,'actor'=>$this->composition->host->actor]);
    }

    public function write(ConfirmedAction $grant,ActionSubmission $submission,?int $epoch=null): void
    {
        $result=$this->service($grant,$submission,$epoch)->execute();
        if ($result->status===LookupStatus::Failed) { throw new ContractViolation(ErrorCode::AccessDenied); }
    }

    public function reconcile(ConfirmedAction $grant,ActionSubmission $submission,?int $epoch=null): void
    {
        $this->service($grant,$submission,$epoch)->reconcile();
    }

    private function service(ConfirmedAction $grant,ActionSubmission $submission,?int $epoch=null): Service
    {
        $c=$this->composition;
        $c->environment->authorize($submission->request->context);
        $c->actions->status($grant->action,$submission->request->context);
        $ledger=new Ledger($c->environment,$grant,$submission,$c->registry,$c->policy,$epoch);
        $port=new class($c,$this->ownerPdo,$this->schema,$submission,$ledger->epoch) implements OwnerPort {
            public function __construct(private Composition $c,private PDO $pdo,private string $schema,
                private ActionSubmission $submission,private int $epoch) {}
            public function namespace(): string { return 'example.documents'; }
            public function strategy(): Strategy { return Strategy::NativeKey; }
            public function execute(Request $request): LookupResult
            {
                $this->c->environment->db->assertIdle();
                $db=new Connection($this->pdo,$this->schema);
                return $db->transaction(function () use ($db,$request): LookupResult {
                    $tenant=$request->action->tenant->value;
                    $intent=$db->row('SELECT action_ref,request_hash FROM '.$db->table('effect_intents').'
                        WHERE tenant_id=:tenant AND effect_key=:effect AND owner_operation_key=:operation AND claim_epoch=:epoch',
                        ['tenant'=>$tenant,'effect'=>$request->effectKey,'operation'=>$request->operationKey,'epoch'=>$request->requestedEpoch]);
                    if ($intent===null || 'sha-256:'.Connection::text($intent,'request_hash')!==$request->requestHash->value
                        || $request->requestHash->value!==$this->submission->request->inputHash->value
                        || $request->requestRef->value!==$this->submission->request->inputRef->value) { throw new IntegrityConflict('Owner immutable request conflict'); }
                    $current=$db->row('SELECT state FROM '.$db->table('action_current').' WHERE tenant_id=:tenant AND id=:action FOR UPDATE',
                        ['tenant'=>$tenant,'action'=>$request->action->value]);
                    /** @var array<string,mixed> $state */
                    $state=json_decode($current===null?'{}':Connection::text($current,'state'),true,512,JSON_THROW_ON_ERROR);
                    if (($state['epoch']??null)!==$this->epoch || ($state['cancellation_requested']??true)) { throw new ContractViolation(ErrorCode::StaleClaim); }
                    $unexpired=$db->row('SELECT id FROM '.$db->table('confirmation_previews').'
                        WHERE tenant_id=:tenant AND action_ref=:action AND (binding->>\'expires_at\')::timestamptz>clock_timestamp()',
                        ['tenant'=>$tenant,'action'=>$request->action->value]);
                    if ($unexpired===null) { throw new ContractViolation(ErrorCode::ConfirmationInvalid); }
                    if (!$this->allowed($db,$request)) { return $this->result($request,LookupStatus::Failed,'owner:permission-denied:'.substr($request->effectKey,8)); }
                    $db->execute('INSERT INTO '.$db->table('example_owner_writes').' (tenant_id,operation_key,request_hash,payload)
                        VALUES (:tenant,:operation,:hash,CAST(:payload AS jsonb)) ON CONFLICT (tenant_id,operation_key) DO NOTHING',
                        ['tenant'=>$tenant,'operation'=>$request->operationKey,'hash'=>$request->requestHash->value,'payload'=>$this->submission->inputJson]);
                    return $this->receipt($db,$request);
                });
            }
            public function lookup(Request $request): LookupResult
            {
                $this->c->environment->db->assertIdle();
                $db=new Connection($this->pdo,$this->schema);
                return $db->transaction(function () use ($db,$request): LookupResult {
                    if (!$this->allowed($db,$request)) { throw new ContractViolation(ErrorCode::AccessDenied); }
                    return $this->receipt($db,$request);
                });
            }
            private function allowed(Connection $db,Request $request): bool
            {
                $permission=$db->row('SELECT allowed FROM '.$db->table('example_owner_permissions').' WHERE tenant_id=:tenant AND actor_ref=:actor FOR SHARE',
                    ['tenant'=>$request->action->tenant->value,'actor'=>$request->context->identity->actorRef->value]);
                return $permission!==null && $permission['allowed']===true;
            }
            private function receipt(Connection $db,Request $request): LookupResult
            {
                $receipt=$db->row('SELECT request_hash,payload FROM '.$db->table('example_owner_writes').' WHERE tenant_id=:tenant AND operation_key=:operation',
                    ['tenant'=>$request->action->tenant->value,'operation'=>$request->operationKey]);
                // Missing row does not prove that an older request cannot still arrive.
                if ($receipt===null) { return $this->result($request,LookupStatus::Unknown); }
                if (Connection::text($receipt,'request_hash')!==$request->requestHash->value
                    || (new Jcs())->canonicalizeJson(Connection::text($receipt,'payload'))!==(new Jcs())->canonicalizeJson($this->submission->inputJson)) {
                    throw new IntegrityConflict('Owner receipt request binding conflict');
                }
                return $this->result($request,LookupStatus::Succeeded,$request->operationKey);
            }
            private function result(Request $request,LookupStatus $status,?string $receipt=null): LookupResult
            {
                return new LookupResult($status,new Ref($request->action->tenant,$request->operationKey),$request->ownerNamespace,
                    $request->requestHash,$receipt===null?null:new Ref($request->action->tenant,$receipt));
            }
        };
        return new Service($ledger,$port);
    }
}
