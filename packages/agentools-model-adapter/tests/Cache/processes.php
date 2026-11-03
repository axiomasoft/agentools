<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php'; require_once __DIR__.'/PgFixture.php'; require_once __DIR__.'/Process.php';
use AgenToolsPlatform\Core\Common\IntegrityConflict;
use AgenToolsPlatform\Core\Models\Cache\ProviderResult;
use AgenToolsPlatform\ModelAdapter\Tests\Cache\{PgFixture,Process,Proof};
use AgenToolsPlatform\Tests\Harness as H;

Proof::run([
 'cache.pg-competing-quota'=>static function (): void {
    $p=new PgFixture(); $workers=[]; try {
        $a=new Process($p->schema,'quota-a'); $b=new Process($p->schema,'quota-b'); $workers=[$a,$b]; H::check($a->backend!==$b->backend);
        $p->database->pdo->beginTransaction();
        $p->repository->db->execute('SELECT pg_advisory_xact_lock(hashtextextended(:key,0))',['key'=>$p->repository->db->table('model_cache_entry').':alpha']);
        $a->go(); $b->go(); $end=microtime(true)+8; $blocked=0;
        do { $blocked=(int)$p->sql("SELECT count(*) FROM pg_stat_activity WHERE pid IN(:a,:b) AND wait_event_type='Lock'",['a'=>$a->backend,'b'=>$b->backend])->fetchColumn(); if ($blocked===2) { break; } usleep(1000); } while (microtime(true)<$end);
        H::check($blocked===2); $p->database->pdo->commit(); $results=[$a->line()['status'],$b->line()['status']]; $exits=[$a->finish(),$b->finish()]; $workers=[]; sort($results);
        H::check($results===['accepted','refused'] && $exits===[0,0] && $p->sql('SELECT count(*) FROM model_cache_warm')->fetchColumn()===1);
        Proof::$observations['cache.pg-competing-quota']=['backends'=>[$a->backend,$b->backend],'blocked'=>$blocked,'exits'=>$exits,'results'=>$results,'rows'=>1];
    } finally { foreach ($workers as $w) { $w->kill(); } $p->drop(); }
 },
 'cache.pg-competing-use-revoke'=>static function (): void {
    $p=new PgFixture(); $workers=[]; try {
        $p->repository->put($p->f->descriptor()); $use=new Process($p->schema,'use'); $revoke=new Process($p->schema,'revoke'); $workers=[$use,$revoke];
        $use->go(); H::check($use->line()['boundary']==='admission-ready'); $revoke->go(); H::check($revoke->line()['status']==='invalidated');
        $use->go(); H::check($use->line()['status']==='denied'); $exits=[$use->finish(),$revoke->finish()]; $workers=[]; H::check($exits===[0,0]);
        H::check(!$p->repository->get($p->f->key())->usable($p->f->now));
        Proof::$observations['cache.pg-competing-use-revoke']=['backends'=>[$use->backend,$revoke->backend],'exits'=>$exits,'after_revoke'=>'denied'];
    } finally { foreach ($workers as $w) { $w->kill(); } $p->drop(); }
 },
 'cache.pg-revoke-crash-windows'=>static function (): void {
    $windows=[];
    foreach (['before-commit','after-commit'] as $mode) {
        $p=new PgFixture(); $w=null; try {
            $f=$p->f; $p->repository->put($f->descriptor()); $barrier=$p->schema.':crash';
            if ($mode==='before-commit') {
                $p->sql('SELECT pg_advisory_lock(hashtextextended(:key,0))',['key'=>$barrier]);
                $p->sql("CREATE FUNCTION cache_commit_barrier() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN PERFORM pg_advisory_xact_lock(hashtextextended('".$barrier."',0)); RETURN NEW; END \$\$");
                $p->sql('CREATE TRIGGER cache_commit_barrier AFTER INSERT ON model_cache_fence FOR EACH ROW EXECUTE FUNCTION cache_commit_barrier()');
                $w=new Process($p->schema,'revoke'); $w->go(); $end=microtime(true)+8; $blocked=false;
                do { $blocked=$p->sql("SELECT wait_event_type='Lock' FROM pg_stat_activity WHERE pid=:pid",['pid'=>$w->backend])->fetchColumn()===true; if ($blocked) { break; } usleep(1000); } while (microtime(true)<$end);
                H::check($blocked); $signal=$w->kill(); $w=null; $p->sql('SELECT pg_advisory_unlock(hashtextextended(:key,0))',['key'=>$barrier]);
                H::check($p->sql('SELECT count(*) FROM model_cache_fence')->fetchColumn()===0 && $p->sql('SELECT count(*) FROM model_cache_delete')->fetchColumn()===0);
                H::check($p->repository->get($f->key())->usable($f->now));
            } else {
                $w=new Process($p->schema,'after-local-revoke'); $w->go(); H::check($w->line()['boundary']==='after-local-revoke'); $signal=$w->kill(); $w=null;
                H::check($p->sql('SELECT count(*) FROM model_cache_fence')->fetchColumn()===1 && $p->sql('SELECT count(*) FROM model_cache_delete')->fetchColumn()===1);
                $fresh=new PgFixture($p->schema); H::check(!$fresh->repository->get($fresh->f->key())->usable($fresh->f->now));
                H::check($fresh->f->manager->reconcileDeletion($fresh->f->now) && $fresh->f->provider->deletes===1);
            }
            H::check($signal['signaled'] && $signal['signal']===9 && $signal['exit']!==0); $windows[$mode]=$signal;
        } finally { if ($w!==null) { $w->kill(); } $p->drop(); }
    }
    Proof::$observations['cache.pg-revoke-crash-windows']=$windows;
 },
 'cache.pg-delete-ack-crash'=>static function (): void {
    $p=new PgFixture(); $w=null; try {
        $p->repository->put($p->f->descriptor()); $w=new Process($p->schema,'delete-ack'); $w->go(); H::check($w->line()['boundary']==='delete-ack'); $signal=$w->kill(); $w=null;
        H::check($signal['signaled'] && $signal['signal']===9 && $p->sql('SELECT status FROM model_cache_delete')->fetchColumn()==='unknown');
        $p->sql('UPDATE model_cache_delete SET next_at=clock_timestamp()'); $fresh=new PgFixture($p->schema);
        $fresh->f->provider->onStatus=function ($op) use ($fresh): ProviderResult {
            H::check($fresh->sql('SELECT kind FROM host_cache_receipts WHERE id=:id',['id'=>$op->value])->fetchColumn()==='delete');
            return new ProviderResult('deleted',receipt:$fresh->f->ref('verified-owner-receipt'));
        };
        H::check($fresh->f->manager->reconcileDeletion($fresh->f->now) && $fresh->f->provider->deletes===0 && $p->sql('SELECT count(*) FROM host_cache_receipts')->fetchColumn()===1);
        Proof::$observations['cache.pg-delete-ack-crash']=$signal+['owner_operations'=>1,'lookup_same_key'=>true];
    } finally { if ($w!==null) { $w->kill(); } $p->drop(); }
 },
 'cache.pg-warm-reply-crash'=>static function (): void {
    $p=new PgFixture(); $w=null; try {
        $w=new Process($p->schema,'warm-ack'); $w->go(); H::check($w->line()['boundary']==='warm-ack'); $signal=$w->kill(); $w=null;
        $p->sql("UPDATE model_cache_warm SET updated_at=clock_timestamp()-interval '1 minute'"); $fresh=new PgFixture($p->schema);
        H::check($fresh->f->manager->reconcileWarm() && $fresh->f->provider->creates===0 && $fresh->f->budget->releases===0);
        H::check($p->sql("SELECT usage->>'certainty' FROM model_cache_warm")->fetchColumn()==='unknown' && $p->sql('SELECT count(*) FROM accounting_usage')->fetchColumn()===1);
        H::check($p->sql('SELECT count(*) FROM host_cache_receipts')->fetchColumn()===1 && $p->sql('SELECT count(*) FROM model_cache_entry')->fetchColumn()===0);
        Proof::$observations['cache.pg-warm-reply-crash']=$signal+['owner_operations'=>1,'usage_facts'=>1,'exposure'=>'unknown','automatic_release'=>false];
    } finally { if ($w!==null) { $w->kill(); } $p->drop(); }
 },
 'cache.pg-stale-warm-result'=>static function (): void {
    $p=new PgFixture(); try {
        $f=$p->f; $intent=$f->accounting->intent($f->key(),$f->warm(),$f->now); $p->repository->beginWarm($intent,$f->policy()); $p->repository->sent($intent,$f->ref('reservation'));
        $p->sql("UPDATE model_cache_warm SET updated_at=clock_timestamp()-interval '1 minute'"); H::check($f->manager->reconcileWarm());
        $reply=new ProviderResult('created',$f->ref('late-resource'),$f->warm()->expiresAt); $json=$f->accounting->fact($intent,$reply);
        H::rejects(fn ()=>$p->repository->finishWarm($intent,$f->descriptor(),$json,'known'),IntegrityConflict::class);
        H::check($p->sql('SELECT count(*) FROM model_cache_entry')->fetchColumn()===0 && $p->sql('SELECT count(*) FROM accounting_usage')->fetchColumn()===1);
    } finally { $p->drop(); }
 },
],true);
