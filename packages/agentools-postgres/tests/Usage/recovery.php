<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/Proof.php';

use AgenToolsPlatform\Core\Common\UtcInstant;
use AgenToolsPlatform\Laravel\Usage\Worker;
use AgenToolsPlatform\Postgres\Budget\Reservations;
use AgenToolsPlatform\Postgres\Tests\Usage\{Fixture,Meter,Proof};
use AgenToolsPlatform\Postgres\Usage\{AccountingPending,Dispatcher,BacklogPolicy,Repository};
use AgenToolsPlatform\Tests\Harness as H;

Proof::run([
    'ECO-ADM-003'=>static function (): void {
        $f=new Fixture(); try {
            $f->invoke(); $f->owner->lost='settle'; H::rejects(fn ()=>$f->budget->settle($f->reservation(),$f->usageId()),AccountingPending::class);
            H::check($f->budget->checkpoint($f->rig->admission())['status']==='unknown');
            $f->rig->sql("UPDATE accounting_operation SET lease_until=clock_timestamp()-interval '1 second'");
            $fresh=new Fixture($f->schema); H::check($fresh->budget->reconcileOne());
            H::check($fresh->budget->checkpoint($fresh->rig->admission())['status']==='settled');
            H::check($f->rig->sql("SELECT sum(calls) FROM host_accounting WHERE kind='settle'")->fetchColumn()==1);
            H::check($f->rig->sql("SELECT key FROM host_lookups WHERE kind='settle'")->fetchColumn()===$f->rig->admission()->operationKey->value);
            H::check($f->rig->sql("SELECT count(*) FROM host_accounting WHERE kind='release'")->fetchColumn()===0);
        } finally { $f->drop(); }
    },
    'accounting.lost-admit-ack'=>static function (): void {
        $f=new Fixture(); try {
            $f->owner->lost='admit'; H::check($f->budget->admit($f->rig->admission())->status==='unknown');
            H::check($f->rig->count('host_admissions')===1 && $f->rig->count('host_provider')===0);
            $f->owner->lost=null; H::check($f->budget->status($f->rig->admission())->status==='admitted');
            H::check($f->rig->count('host_admissions')===1 && $f->owner->guards>1);
            $read=new \AgenToolsPlatform\Core\Budget\AdmissionRequest($f->ref('status-outage'),$f->rig->admission()->content,$f->rig->fixture->hasher);
            $f->rig->budget->outcome='unknown'; H::check($f->budget->status($read)->status==='unknown');
            $f->rig->budget->outcome='admitted'; H::check($f->budget->reconcileOne());
            H::check($f->budget->checkpoint($read)['status']==='active' && $f->rig->count('host_provider')===0 && $f->rig->count('host_business')===1);
        } finally { $f->drop(); }
    },
    'accounting.lost-release-renew'=>static function (): void {
        foreach (['release','renew'] as $kind) {
            $f=new Fixture(); try {
                $f->budget->admit($f->rig->admission()); $reservation=$f->reservation(); $deadline=new UtcInstant('2030-02-01T00:00:00Z');
                if ($kind==='release') { $f->budget->proveNotSent($reservation,$f->ref('transport-not-sent')); }
                $f->owner->lost=$kind;
                $send=fn ()=>$kind==='release'?$f->budget->release($reservation,'proven_not_sent:transport-not-sent'):$f->budget->renew($reservation,$deadline);
                H::rejects($send,AccountingPending::class); H::check($f->budget->checkpoint($f->rig->admission())['status']==='unknown');
                $f->owner->lost=null; $send();
                H::check($f->budget->checkpoint($f->rig->admission())['status']===($kind==='release'?'released':'active'));
                H::check($f->rig->sql('SELECT sum(calls) FROM host_accounting')->fetchColumn()==1);
                H::check($f->rig->sql('SELECT key FROM host_lookups WHERE kind=:kind',['kind'=>$kind])->fetchColumn()===$f->rig->admission()->operationKey->value);
            } finally { $f->drop(); }
        }
    },
    'accounting.no-lookup-no-retry'=>static function (): void {
        $f=new Fixture(); try {
            $f->invoke(); $f->owner->lost='settle'; H::rejects(fn ()=>$f->budget->settle($f->reservation(),$f->usageId()),AccountingPending::class);
            $without=new Reservations($f->usage,$f->owner,$f->owner,$f->owner,$f->rig->fixture->hasher);
            H::rejects(fn ()=>$without->settle($f->reservation(),$f->usageId()),AccountingPending::class);
            H::check($f->budget->checkpoint($f->rig->admission())['status']==='unknown' && $f->rig->sql('SELECT sum(calls) FROM host_accounting')->fetchColumn()==1);
            H::rejects(fn ()=>$f->budget->proveNotSent($f->reservation(),$f->ref('invented-ttl-proof')),AccountingPending::class);
        } finally { $f->drop(); }
    },
    'accounting.export-replay-backoff'=>static function (): void {
        $f=new Fixture(policy:new BacklogPolicy(20,3600,2,1,1)); try {
            $f->invoke(); $meter=new Meter($f); $meter->lost=true; $d=new Dispatcher($f->usage,$meter);
            H::check($d->dispatchOne()); H::check($f->usage->exportState($f->usageId())['status']==='unknown');
            $f->rig->sql('UPDATE accounting_export SET next_at=clock_timestamp()'); H::check($d->dispatchOne());
            H::check($f->usage->exportState($f->usageId())['status']==='exported' && $f->rig->sql('SELECT calls FROM host_meter')->fetchColumn()===1);
            // A separate unknown fact hits its bounded retry policy without being erased.
            $values=new \AgenToolsPlatform\Tests\Usage\Fixture();
            $event=$values->event([],['tenant_ref'=>'tenant-a','event_id'=>'outage-event'],'outage-inv'); $f->usage->append($event); $meter->unavailable=true;
            for ($i=0;$i<3;++$i) { $f->rig->sql('UPDATE accounting_export SET next_at=clock_timestamp(),lease_until=NULL'); $d->dispatchOne(); }
            H::check($f->usage->exportState($event->id)['status']==='manual_resolution_required' && $f->rig->count('accounting_usage')===2);
            H::check($f->usage->exportState($event->id)['attempts']===2);
            $stale=$values->event([],['tenant_ref'=>'tenant-a','event_id'=>'stale-export'],'stale-inv'); $f->usage->append($stale);
            $meter->unavailable=false; $meter->lost=false;
            $meter->afterCommit=function () use ($f,$stale): void {
                $other=new \AgenToolsPlatform\Postgres\Tests\IsolatedDatabase();
                $db=new \AgenToolsPlatform\Postgres\Runtime\Connection($other->pdo,$f->schema);
                $db->transaction(fn ()=>$db->execute('UPDATE '.$db->table('accounting_export').' SET epoch=epoch+1,status=\'unknown\',lease_until=NULL,next_at=clock_timestamp()
                    WHERE tenant_id=:tenant AND id=:id',['tenant'=>$stale->id->tenant->value,'id'=>$stale->id->value]));
            };
            H::check($d->dispatchOne() && $f->usage->exportState($stale->id)['status']==='unknown' && $f->usage->exportState($stale->id)['ack_hash']===null);
            $meter->afterCommit=null; H::check($d->dispatchOne() && $f->usage->exportState($stale->id)['status']==='exported');
        } finally { $f->drop(); }
    },
    'accounting.worker-reset-redaction'=>static function (): void {
        $f=new Fixture(); try {
            $f->invoke(); $beta=new Fixture($f->schema,'tenant-b'); $beta->invoke();
            $seen=[]; $factory=function ($tenant) use ($f,$beta,&$seen): Dispatcher {
                $db=$f->usage->db; $row=$db->row("SELECT current_setting('agentools.tenant',true) AS tenant"); H::check($row['tenant']===null || $row['tenant']==='');
                $db->execute("SELECT set_config('agentools.tenant',:tenant,false)",['tenant'=>$tenant->value]); $seen[]=$tenant->value;
                $fixture=$tenant->value==='tenant-a'?$f:$beta;
                $repository=new Repository($f->usage->db,$tenant,$f->rig->fixture->hasher,$f->usage->policy);
                return new Dispatcher($repository,new Meter($fixture));
            };
            $worker=new Worker($f->usage->db,$factory); H::check($worker->run($f->usage->tenant) && $worker->run($beta->usage->tenant));
            H::check($seen===['tenant-a','tenant-b']);
            $wrong=new Worker($f->usage->db,fn ($tenant)=>new Dispatcher($beta->usage,new Meter($beta)));
            H::rejects(fn ()=>$wrong->run($f->usage->tenant),\AgenToolsPlatform\Core\Common\ContractViolation::class);
            $failed=new Worker($f->usage->db,function ($tenant) use ($f): \AgenToolsPlatform\Postgres\Usage\Dispatcher {
                $f->usage->db->execute("SELECT set_config('agentools.tenant','should-reset',false)"); throw new AccountingPending();
            });
            H::rejects(fn ()=>$failed->run($f->usage->tenant),AccountingPending::class);
            $reset=$f->usage->db->row("SELECT current_setting('agentools.tenant',true) AS tenant"); H::check($reset['tenant']===null || $reset['tenant']==='');
            $f->owner->lost='settle';
            try { $f->budget->settle($f->reservation(),$f->usageId()); H::check(false); }
            catch (AccountingPending $error) { H::check(!str_contains($error->getMessage(),'secret') && $error->getPrevious()===null); }
            H::check(!str_contains(json_encode($f->usage->event($f->usageId())->toArray(),JSON_THROW_ON_ERROR),'Read a fact'));
        } finally { $f->drop(); }
    },
],true);
