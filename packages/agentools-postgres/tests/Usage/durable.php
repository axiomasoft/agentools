<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/Proof.php';

use AgenToolsPlatform\Core\Budget\{AdmissionConflict,AdmissionRequest};
use AgenToolsPlatform\Core\Common\{ContractViolation,Ref,TenantId};
use AgenToolsPlatform\Core\Runtime\{ExchangeResult,InvocationCheckpoint};
use AgenToolsPlatform\Core\Usage\{UsageConflict,UsageEvent,UsageFold};
use AgenToolsPlatform\Postgres\Tests\Usage\{Fixture,Meter,Proof};
use AgenToolsPlatform\Postgres\Usage\{AccountingPending,BacklogPolicy,Dispatcher,Repository};
use AgenToolsPlatform\Tests\Harness as H;
use AgenToolsPlatform\Tests\Usage\Fixture as Values;

Proof::run([
    'CM-040'=>static function (): void {
        $f=new Fixture(); try {
            $f->rig->provider->lost=true; $saved=$f->invoke(); H::check($saved->stage()==='unknown');
            $event=$f->usage->event($f->usageId()); H::check($event->certainty->value==='unknown' && $event->units->toArray()['input_uncached_tokens']===null);
            H::check($f->budget->checkpoint($f->rig->admission())['status']==='unknown');
            H::rejects(fn ()=>$f->budget->release($f->reservation(),'lease expired'),AccountingPending::class);
            H::rejects(fn ()=>$f->budget->settle($f->reservation(),$f->usageId()),AccountingPending::class);
            H::check($f->rig->count('host_accounting')===0 && $f->rig->count('host_provider')===1);
        } finally { $f->drop(); }
    },
    'accounting.atomic-response-export'=>static function (): void {
        $f=new Fixture(); try {
            $r=$f->rig; $request=$r->admission(); $saved=$r->journal->open($r->context,$r->lease(),$r->plan,$request);
            $saved=$r->journal->admitted($r->context,$r->plan,$saved,$f->budget->admit($request));
            $saved=$r->journal->outbound($r->context,$r->plan,$saved); $result=ExchangeResult::response($r->plan,$r->response(false),$r->fixture->hasher);
            $payload=$f->ref('exchange-result'); $r->payloads->put($r->context,$payload,$result->hash(),$result->json);
            $r->sql("CREATE FUNCTION fail_export() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'forced rollback'; END \$\$");
            $r->sql('CREATE CONSTRAINT TRIGGER fail_export AFTER INSERT ON accounting_export DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fail_export()');
            H::rejects(fn ()=>$r->journal->result($r->context,$r->plan,$saved,$payload,$result->hash(),$result),\Throwable::class);
            H::check($r->count('invocation_results')===0 && $r->count('usage_versions')===0 && $r->count('accounting_usage')===0 && $r->count('accounting_export')===0);
            $r->sql('DROP TRIGGER fail_export ON accounting_export');
            $r->journal->result($r->context,$r->plan,$saved,$payload,$result->hash(),$result);
            H::check($r->count('invocation_results')===1 && $r->count('usage_versions')===1 && $r->count('accounting_export')===1);
            H::check($r->sql("SELECT count(*) FROM outbox WHERE event_type='invocation.usage'")->fetchColumn()===0);
        } finally { $f->drop(); }
    },
    'accounting.identity-quarantine'=>static function (): void {
        $f=new Fixture(); try {
            $f->invoke(); $event=$f->usage->event($f->usageId());
            H::check($f->usage->append($event)===$f->usage->append($event) && $f->rig->count('accounting_usage')===1);
            $d=$event->toArray(); $d['units']['output_tokens']=999; $bad=UsageEvent::fromArray($d,$f->rig->fixture->hasher);
            H::rejects(fn ()=>$f->usage->append($bad),UsageConflict::class);
            H::check($f->rig->count('accounting_quarantine')===1 && $f->usage->exportState($event->id)['status']==='quarantined');
            $d=$event->toArray(); $d['event_id']='different-id-same-version';
            H::rejects(fn ()=>$f->usage->append(UsageEvent::fromArray($d,$f->rig->fixture->hasher)),UsageConflict::class);
            H::check($f->rig->count('accounting_usage')===1 && $f->rig->count('accounting_quarantine')===2);
            H::check(!(new Dispatcher($f->usage,new Meter($f)))->dispatchOne() && $f->rig->count('host_meter')===0);
            H::rejects(fn ()=>$f->usage->event(new Ref(new TenantId('other'),$event->id->value)),ContractViolation::class);
            H::rejects(fn ()=>$f->usage->appendInTransaction($event),LogicException::class);
            $f->rig->sql("UPDATE accounting_schema_versions SET source_hash='mismatch'");
            H::rejects(fn ()=>\AgenToolsPlatform\Postgres\Usage\Schema::install($f->database->pdo,$f->schema),\AgenToolsPlatform\Core\Common\IntegrityConflict::class);
        } finally { $f->drop(); }
    },
    'accounting.correction-replacement'=>static function (): void {
        $f=new Fixture(); try {
            $v=new Values(); $a=$v->event(['input_total'=>100,'cache_read'=>40],['tenant_ref'=>'tenant-a','certainty'=>'preliminary']);
            $d=$a->toArray(); $d['event_id']='final-correction'; $d['source']['usage_version']=2; $d['supersedes_event_id']=$a->id->value;
            $d['certainty']='final'; $d['units']['output_tokens']=12; $b=UsageEvent::fromArray($d,$f->rig->fixture->hasher);
            $f->usage->append($a); $f->usage->append($b); $meter=new Meter($f); $dispatcher=new Dispatcher($f->usage,$meter);
            H::check($dispatcher->dispatchOne() && $dispatcher->dispatchOne() && !$dispatcher->dispatchOne());
            $fold=UsageFold::fold([$b,$a,$a]); H::check(count($fold->history)===2 && count($fold->active)===1 && array_values($fold->active)[0]->id->value===$b->id->value);
            H::rejects(fn ()=>$f->rig->sql('UPDATE accounting_usage SET document=document'),\PDOException::class);
            H::check($f->rig->count('host_meter')===2);
        } finally { $f->drop(); }
    },
    'accounting.settle-once'=>static function (): void {
        $f=new Fixture(); try {
            $f->invoke(); $f->budget->settle($f->reservation(),$f->usageId()); $f->budget->settle($f->reservation(),$f->usageId());
            H::check($f->budget->checkpoint($f->rig->admission())['status']==='settled');
            H::check($f->rig->sql('SELECT sum(calls) FROM host_accounting')->fetchColumn()==1 && $f->rig->count('host_business')===1);
            $event=$f->usage->event($f->usageId()); $d=$event->toArray(); $d['event_id']='settlement-correction';
            $d['source']['usage_version']=2; $d['supersedes_event_id']=$event->id->value; $d['units']['output_tokens']=13;
            $correction=UsageEvent::fromArray($d,$f->rig->fixture->hasher); $f->usage->append($correction);
            $f->budget->settle($f->reservation(),$correction->id); $f->budget->settle($f->reservation(),$correction->id);
            H::check($f->rig->sql('SELECT sum(calls) FROM host_accounting')->fetchColumn()==2 && $f->rig->count('host_business')===1);
            H::check($f->rig->sql("SELECT count(*) FROM accounting_operation WHERE payload->>'supersedes_event_id'=:prior",
                ['prior'=>$event->id->value])->fetchColumn()===1);
        } finally { $f->drop(); }
    },
    'accounting.admission-conflict-backlog'=>static function (): void {
        $f=new Fixture(policy:new BacklogPolicy(1,3600,3)); try {
            $r=$f->rig->admission(); $f->budget->admit($r);
            $other=new AdmissionRequest($f->ref('other'),$r->content,$f->rig->fixture->hasher);
            H::rejects(fn ()=>$f->budget->admit($other),AccountingPending::class);
            $bad=new AdmissionRequest($r->operationKey,array_replace($r->content,['purpose'=>'different']),$f->rig->fixture->hasher);
            H::rejects(fn ()=>$f->budget->admit($bad),AdmissionConflict::class);
            H::check($f->budget->checkpoint($r)['status']==='unknown' && $f->rig->count('host_admissions')===1);
        } finally { $f->drop(); }
    },
    'accounting.denial-revocation-before-provider'=>static function (): void {
        foreach (['denied','unavailable','revoked'] as $outcome) {
            $f=new Fixture(); try {
                if ($outcome==='revoked') { $f->owner->revoked=true; } else { $f->rig->budget->outcome=$outcome; }
                H::rejects(fn ()=>$f->invoke(),\Throwable::class); H::check($f->rig->count('host_provider')===0);
            } finally { $f->drop(); }
        }
    },
],true);
