<?php

declare(strict_types=1);
require_once __DIR__.'/PgHarness.php';
require_once __DIR__.'/Process.php';

use AgenToolsPlatform\ChatomBridge\Tests\{Fixture, PgHarness as P, Process};
use AgenToolsPlatform\Core\Common\{ContractViolation, IntegrityConflict};
use AgenToolsPlatform\Core\Context\History\DerivedKind;
use AgenToolsPlatform\Postgres\Inbox\IntakeRepository;
use AgenToolsPlatform\Tests\Harness as H;

function fixture(callable $test,int $ttl=3600):void{$f=new Fixture(retentionSeconds:$ttl);try{$test($f);}finally{$f->close();}}
P::run([
 'chatom.incoming-conflict-before-enqueue'=>static function():void{fixture(static function(Fixture $f):void{
    $changed=new \AgenToolsPlatform\Core\Security\TrustedInput($f->context,$f->input->source,
        new \AgenToolsPlatform\Core\Common\InputPayload($f->input->payload->inputRef,$f->hasher->hash($f->host->tenant,'source-content','Changed source'),
            $f->input->payload->conversationRef,$f->input->payload->messageRef));
    $incoming=new \AgenToolsPlatform\ChatomBridge\Incoming($f->env,$f->store);
    H::rejects(fn()=>$incoming->accept($changed,$f->snapshot,$f->ref('chatom:source:msg-1'),1),IntegrityConflict::class);
    H::check($f->count('inbox')===1 && $f->count('outbox')===1 && $f->count('runs')===1);
 });},
 'chatom.competing-claims'=>static function():void{fixture(static function(Fixture $f):void{
    $intent=$f->stage();$a=new Process('claim',$f->schema);$b=new Process('claim',$f->schema);
    $pa=$a->line();$pb=$b->line();$a->go();$b->go();$ra=$a->line();$rb=$b->line();$exits=[$a->finish(),$b->finish()];
    H::check($pa['backend']!==$pb['backend'] && $exits===[0,0] && (int)$ra['claimed']+(int)$rb['claimed']===1);
    H::check($f->count('delivery_intents')===1 && $f->count('fixture_messages')===0);
    P::$observations['chatom.competing-claims']=['backends'=>[$pa['backend'],$pb['backend']],'exits'=>$exits,'claims'=>(int)$ra['claimed']+(int)$rb['claimed'],'rows'=>$f->count('delivery_intents')];
 });},
 'chatom.crash-commit-before-dispatch'=>static function():void{fixture(static function(Fixture $f):void{
    $worker=new Process('commit',$f->schema);$worker->line();$worker->go();$point=$worker->line();$signal=$worker->kill();
    H::check($point['point']==='response-intent-committed' && $signal['signaled'] && $signal['signal']===9 && $signal['exit']!==0);
    H::check($f->delivery->pending($f->context)===[$point['key']] && $f->count('fixture_messages')===0);
    $replay=new IntakeRepository($f->env,new \AgenToolsPlatform\Postgres\Inbox\SourceAdmission())->accept($f->input,$f->snapshot);
    H::check($replay->toArray()===$f->ack->toArray() && $f->count('inbox')===1 && $f->count('outbox')===1);
    H::check($f->delivery->relay($f->context,$point['key'])['state']==='delivered' && $f->count('fixture_messages')===1);
    P::$observations['chatom.crash-commit-before-dispatch']=$signal+['pending_recovered'=>true,'inbox'=>$f->count('inbox'),'outbox'=>$f->count('outbox')];
 });},
 'chatom.crash-destination-before-receipt'=>static function():void{fixture(static function(Fixture $f):void{
    $worker=new Process('destination',$f->schema);$original=$worker->line();$worker->go();$point=$worker->line();$signal=$worker->kill();
    H::check($point['point']==='destination-committed' && $signal['signaled'] && $signal['signal']===9 && $signal['exit']!==0);
    H::check($f->delivery->status($f->context,$point['key'])['state']==='sending' && $f->count('fixture_messages')===1 && $f->count('delivery_receipts')===0);
    usleep(300000); // Actual configured lease expiry, no lease/fence bypass.
    $restart=new Process('recover',$f->schema);$resumed=$restart->line();$restart->go();$result=$restart->line();$exit=$restart->finish();
    H::check($exit===0 && $original['backend']!==$resumed['backend'] && $result['state']['state']==='delivered'
        && $result['messages']===1 && $result['responses']===1 && $result['invocations']===0);
    P::$observations['chatom.crash-destination-before-receipt']=$signal+['backends'=>[$original['backend'],$resumed['backend']],
        'recovery_exit'=>$exit,'destination_messages'=>$result['messages'],'recovered_receipt'=>$result['state']['receipt'],'invocations'=>$result['invocations']];
 });},
 'chatom.invalidation-partial-recovery'=>static function():void{fixture(static function(Fixture $f):void{
    $intent=$f->stage();$message=$f->message();$f->graph->register($f->context,$f->ref('summary'),DerivedKind::Summary,[$message]);
    $f->graph->register($f->context,$f->ref('cache'),DerivedKind::ContextCache,[$message]);
    $worker=new Process('closure',$f->schema);$worker->line();$worker->go();$point=$worker->line();$signal=$worker->kill();
    H::check($point['point']==='source-closed' && $f->delivery->pending($f->context)===[]);
    $f->host->sourceAllowed=false;
    $f->invalidation->apply($f->context,$intent->source,$message,2,'delete-recovery','source_deleted');
    H::check(!$f->graph->usable($f->context,$f->ref('summary')) && !$f->graph->usable($f->context,$f->ref('cache')));
    H::rejects(fn()=>$f->invalidation->apply($f->context,$intent->source,$message,3,'delete-recovery','source_deleted'),IntegrityConflict::class);
    P::$observations['chatom.invalidation-partial-recovery']=$signal+['summary_invalidated'=>true,'context_cache_invalidated'=>true];
 });},
 'chatom.expiry-purge-keeps-owner-transcript'=>static function():void{fixture(static function(Fixture $f):void{
    $intent=$f->stage();H::check($f->delivery->relay($f->context,$intent->key)['state']==='delivered');
    usleep(1100000);H::check($f->payloads->status($f->context,$intent->payload)==='expired');
    H::rejects(fn()=>$f->vault->get($f->context,$intent->payload),ContractViolation::class);
    $f->payloads->purge($f->context,$intent->payload);
    H::check($f->payloads->status($f->context,$intent->payload)==='purged' && $f->count('fixture_messages')===1);
    H::check($f->delivery->relay($f->context,$intent->key)['state']==='delivered');
 },1);},
 'chatom.response-version-new-key'=>static function():void{fixture(static function(Fixture $f):void{
    $first=$f->stage();$f->delivery->relay($f->context,$first->key);$next=$f->stage('Revised answer',2);
    H::check($next->key!==$first->key && $next->responseVersion===2);$f->delivery->relay($f->context,$next->key);
    $export=json_decode($f->vault->get($f->context,$next->payload),true,512,JSON_THROW_ON_ERROR);
    H::check($export['supersedes']===$first->response->toArray());
    H::check($f->count('responses')===2 && $f->count('fixture_messages')===2 && $f->count('invocation_intents')===0);
 });},
]);
