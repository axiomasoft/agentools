<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Tests\Operations;

require_once dirname(__DIR__).'/bootstrap.php';
require_once dirname(__DIR__,3).'/agentools-core/tests/Actions/Confirmation/Fixture.php';
require_once dirname(__DIR__,3).'/agentools-postgres/tests/Effects/Owner.php';

use AgenToolsPlatform\Core\Actions\Cancellation\{Authority as OperatorAuthority, InterruptedOutput, Operation, OutputControl};
use AgenToolsPlatform\Core\Actions\Confirmation\ConfirmedAction;
use AgenToolsPlatform\Core\Actions\Effects\{ManualAuthority, ManualEvidence, Request};
use AgenToolsPlatform\Core\Common\{ActionId, ContractViolation, ErrorCode, PayloadHash, Ref, RunId};
use AgenToolsPlatform\Laravel\Operations\Operations;
use AgenToolsPlatform\Postgres\Delivery\{DeliveryRepository, DeliverySubmission};
use AgenToolsPlatform\Postgres\Effects\{Ledger, Service};
use AgenToolsPlatform\Postgres\Operations\Audit;
use AgenToolsPlatform\Postgres\Runtime\Connection;
use AgenToolsPlatform\Postgres\Tests\Effects\Owner;
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;
use AgenToolsPlatform\Tests\Confirmation\Fixture as ConfirmationFixture;

final class Authority implements OperatorAuthority, ManualAuthority
{
    public bool $allowed=true;
    public function __construct(private ConfirmationFixture $f) {}
    public function authorize(Operation|Request $request, ?ManualEvidence $evidence=null): void
    {
        $this->f->composition->environment->db->assertIdle();
        if (!$this->allowed) { throw new ContractViolation(ErrorCode::AccessDenied); }
        if ($request instanceof Request) {
            if ($evidence===null || $evidence->evidence->value!=='evidence:verified-owner' || $evidence->operator->value!=='actor') { throw new ContractViolation(ErrorCode::AccessDenied); }
            $db=new Connection((new IsolatedDatabase())->pdo,$this->f->schema);
            $row=$db->row('SELECT request_hash,payload->>\'outcome\' AS outcome FROM '.$db->table('example_owner_writes').' WHERE tenant_id=:tenant AND operation_key=:operation',
                ['tenant'=>$request->action->tenant->value,'operation'=>$request->operationKey]);
            if ($row===null || Connection::text($row,'request_hash')!==$request->requestHash->value
                || Connection::text($row,'outcome')!==$evidence->outcome->status->value
                || $evidence->outcome->receipt?->value!=='owner:receipt:'.$request->operationKey
                || $evidence->basis!=='owner_receipt_verified') { throw new ContractViolation(ErrorCode::AccessDenied); }
        }
    }
}

final class Output implements OutputControl
{
    public int $calls=0;
    public ?int $acknowledged=1;
    public function __construct(private Fixture $f) {}
    public function stop(Operation $operation): InterruptedOutput
    {
        ++$this->calls;
        $this->f->f->composition->environment->db->assertIdle();
        // Another connection must see the already committed operational intent.
        $db=new Connection((new IsolatedDatabase())->pdo,$this->f->f->schema);
        $row=$db->row('SELECT request_hash FROM '.$db->table('operator_operations').' WHERE tenant_id=:tenant AND id=:id',
            ['tenant'=>$operation->id->tenant->value,'id'=>$operation->id->value]);
        if ($row===null || Connection::text($row,'request_hash')!==$operation->hash->value) { throw new \LogicException('Stop intent was not committed'); }
        $control=$db->row('SELECT version,fencing_epoch FROM '.$db->table('delivery_controls').' WHERE tenant_id=:tenant AND id=:id',
            ['tenant'=>$operation->id->tenant->value,'id'=>$operation->target->value]);
        if ($control===null || Connection::number($control,'version')!==$operation->expectedVersion
            || Connection::number($control,'fencing_epoch')!==$operation->expectedEpoch) { throw new ContractViolation(ErrorCode::StaleClaim); }
        $this->f->delivery()->cancel($operation->context,$operation->target->value);
        return new InterruptedOutput(new Ref($operation->id->tenant,$operation->target->value),$operation->kind==='tts_stop'?'tts':'stream','chunks',
            $this->acknowledged,3,$this->acknowledged===null?null:new Ref($operation->id->tenant,'destination:partial-receipt'));
    }
}

final class Fixture
{
    public ConfirmationFixture $f;
    public Authority $authority;
    public Audit $audit;
    public Output $output;
    public Operations $operations;
    public RunId $run;
    public function __construct(?string $schema=null,?\AgenToolsPlatform\Core\Actions\ActionDefinition $definition=null)
    {
        $this->f=new ConfirmationFixture($schema,$definition);
        $c=$this->f->composition;
        if ($schema===null) {
            $hash=new PayloadHash('sha-256:'.str_repeat('a',64));
            (new \AgenToolsPlatform\Postgres\Runtime\SessionRepository($c->environment))->bind($c->context(),
                new \AgenToolsPlatform\Postgres\Runtime\SessionBinding($c->context()->session,
                    new \AgenToolsPlatform\Core\Common\InstanceId($c->host->tenant,'operator-instance'),new Ref($c->host->tenant,'code:operator'),
                    $hash,$hash,new Ref($c->host->tenant,'bundle:operator'),$hash,new Ref($c->host->tenant,'history:operator')));
        }
        $c->actions->submit($c->submission()); // Durable session/action binding, no owner effect.
        $this->authority=new Authority($this->f);
        $this->audit=new Audit($c->environment,$this->authority);
        $this->output=new Output($this);
        $this->operations=new Operations($this->audit,$c->actions,$c->registry,$c->policy,$c->confirmation,$this->output);
        $this->run=new RunId($c->host->tenant,'run:operator');
        if ($schema===null) {
            $db=$c->environment->db;
            $db->execute('INSERT INTO '.$db->table('turns').' (tenant_id,id,session_id,source,source_event_key,payload_hash,input_ref)
                VALUES (:tenant,\'turn:operator\',:session,\'test\',\'operator-source\',:hash,\'input:operator\')',
                ['tenant'=>$c->host->tenant->value,'session'=>$c->context()->session->value,'hash'=>str_repeat('a',64)]);
            $db->execute('INSERT INTO '.$db->table('runs').' (tenant_id,id,turn_id,checkpoint) VALUES (:tenant,:id,\'turn:operator\',\'{"fixture":"operator-test"}\'::jsonb)',
                ['tenant'=>$c->host->tenant->value,'id'=>$this->run->value]);
        }
    }
    public function op(string $kind,ActionId|RunId|Ref $target,int $version,int $epoch,string $key='operator-command',?Ref $evidence=null,?PayloadHash $payload=null,string $reason='requested_stop'): Operation
    {
        $c=$this->f->composition;
        return new Operation(new Ref($c->host->tenant,$key),$kind,$target,$version,$epoch,$reason,$evidence,
            $payload??$c->hasher->hash($c->host->tenant,'operation-input',[]),$c->context(),$c->hasher);
    }
    /** @return array{ConfirmedAction,Ledger,Owner,Service} */
    public function unknown(): array
    {
        $c=$this->f->composition; $p=$this->f->approve(); $grant=$c->confirmation->commit($p->token,$c->submission());
        $this->link($grant->action);
        $ledger=new Ledger($c->environment,$grant,$c->submission(),$c->registry,$c->policy);
        $owner=new Owner(new Connection((new IsolatedDatabase())->pdo,$this->f->schema),$c->environment->db);
        $owner->loseResponse=true; $service=new Service($ledger,$owner);
        try { $service->execute(); } catch (\AgenTools\Effects\EffectReconciliationRequired) { return [$grant,$ledger,$owner,$service]; }
        throw new \LogicException('Expected committed owner response loss');
    }
    public function known(): ConfirmedAction
    {
        $c=$this->f->composition; $p=$this->f->approve(); $grant=$c->confirmation->commit($p->token,$c->submission());
        $this->link($grant->action); $this->f->owner->write($grant,$c->submission()); return $grant;
    }
    public function link(ActionId $action): void
    {
        $db=$this->f->composition->environment->db;
        $db->execute('INSERT INTO '.$db->table('run_steps').' (tenant_id,id,run_id,step_key,action_ref)
            VALUES (:tenant,\'operator-step\',:run,\'operator-step\',:action)',
            ['tenant'=>$action->tenant->value,'run'=>$this->run->value,'action'=>$action->value]);
    }
    public function delivery(): DeliveryRepository
    {
        return new DeliveryRepository($this->f->composition->environment,function (): void {
            $this->f->composition->environment->db->assertIdle();
            if (!$this->authority->allowed) { throw new ContractViolation(ErrorCode::AccessDenied); }
        });
    }
    public function outputIntent(): Ref
    {
        $c=$this->f->composition; $db=$c->environment->db; $tenant=$c->host->tenant;
        $db->execute('INSERT INTO '.$db->table('payload_refs').'(tenant_id,id,session_id,binding_hash,content_hash,encrypted_ref,encryption_key_ref,classification,purpose,retention_until)
            VALUES (:tenant,\'output:payload\',:session,:binding,:hash,\'synthetic:encrypted\',\'synthetic:key\',\'private\',\'document.write\',\'2099-01-01\')',
            ['tenant'=>$tenant->value,'session'=>$c->context()->session->value,'binding'=>str_repeat('b',64),'hash'=>str_repeat('a',64)]);
        $source=new Ref($tenant,'source:operator'); $delivery=$this->delivery(); $delivery->openSource($c->context(),$source,1);
        $proposal=new DeliverySubmission('delivery:operator',$this->run,new Ref($tenant,'response:operator'),1,new Ref($tenant,'output:payload'),
            new PayloadHash('sha-256:'.str_repeat('a',64)),new Ref($tenant,'destination:operator'),$source,1,$c->host->revision,
            new PayloadHash('sha-256:'.str_repeat('b',64)));
        $delivery->submit($c->context(),$proposal); $delivery->claim($c->context(),$proposal->key);
        return new Ref($tenant,$proposal->key);
    }
    public function close(): void { $this->f->close(); }
}
