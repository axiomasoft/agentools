<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/Proof.php';
require_once __DIR__.'/Process.php';

use AgenToolsPlatform\Postgres\Tests\Usage\{Fixture,Meter,Process,Proof};
use AgenToolsPlatform\Postgres\Usage\Dispatcher;
use AgenToolsPlatform\Tests\Harness as H;
use AgenToolsPlatform\Tests\Usage\Fixture as Values;

Proof::run([
    'accounting.competing-connections'=>static function (): void {
        $f=new Fixture(); $workers=[];
        try {
            $a=new Process($f->schema,'append'); $b=new Process($f->schema,'append'); $workers=[$a,$b]; H::check($a->backend!==$b->backend);
            $f->database->pdo->beginTransaction(); $f->usage->lockTenant(); $a->go(); $b->go();
            $deadline=microtime(true)+8; $blocked=0;
            do { $blocked=(int)$f->rig->sql("SELECT count(*) FROM pg_stat_activity WHERE pid IN (:a,:b) AND wait_event_type='Lock'",['a'=>$a->backend,'b'=>$b->backend])->fetchColumn(); if ($blocked===2) { break; } usleep(1000); } while (microtime(true)<$deadline);
            H::check($blocked===2,'Both connections must actually compete for the tenant lock'); $f->database->pdo->commit();
            $ra=$a->line(); $rb=$b->line(); $exits=[$a->finish(),$b->finish()]; $workers=[];
            H::check($exits===[0,0] && $ra===$rb && $f->rig->count('accounting_usage')===1 && $f->rig->count('accounting_export')===1);
            Proof::$observations['accounting.competing-connections']=['backends'=>[$a->backend,$b->backend],'blocked'=>$blocked,'exits'=>$exits,'rows'=>1];
        } finally { foreach ($workers as $w) { $w->kill(); } $f->drop(); }
    },
    'accounting.crash-commit-windows'=>static function (): void {
        $observed=[];
        foreach (['before-commit','after-commit'] as $mode) {
            $f=new Fixture(); try {
                $w=new Process($f->schema,$mode); $w->go(); H::check($w->line()['boundary']===$mode); $signal=$w->kill();
                H::check($signal['signaled'] && $signal['signal']===9 && $signal['exit']!==0);
                H::check($f->rig->count('accounting_usage')===($mode==='before-commit'?0:1));
                $fresh=new Fixture($f->schema); $event=(new Values())->event([],['tenant_ref'=>'tenant-a','event_id'=>'competing-event'],'competing-inv');
                $ack=$fresh->usage->append($event); H::check($ack===$event->acknowledgement() && $f->rig->count('accounting_usage')===1 && $f->rig->count('accounting_export')===1);
                $observed[$mode]=$signal+['facts'=>1,'exports'=>1];
            } finally { $f->drop(); }
        }
        Proof::$observations['accounting.crash-commit-windows']=$observed;
    },
    'accounting.crash-export-ack'=>static function (): void {
        $f=new Fixture(); try {
            $f->invoke(); $w=new Process($f->schema,'export-ack'); $w->go(); H::check($w->line()['boundary']==='export-ack'); $signal=$w->kill();
            H::check($signal['signaled'] && $signal['signal']===9 && $signal['exit']!==0 && $f->rig->count('host_meter')===1);
            $f->rig->sql("UPDATE accounting_export SET lease_until=clock_timestamp()-interval '1 second',next_at=clock_timestamp()");
            $fresh=new Fixture($f->schema); H::check((new Dispatcher($fresh->usage,new Meter($fresh)))->dispatchOne());
            H::check($fresh->usage->exportState($fresh->usageId())['status']==='exported' && $f->rig->sql('SELECT calls FROM host_meter')->fetchColumn()===1);
            Proof::$observations['accounting.crash-export-ack']=$signal+['owner_exports'=>1,'replayed_same_identity'=>true];
        } finally { $f->drop(); }
    },
],true);
