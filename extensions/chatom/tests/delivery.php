<?php

declare(strict_types=1);
require_once __DIR__.'/PgHarness.php';

use AgenToolsPlatform\ChatomBridge\Tests\{Fixture, PgHarness as P};
use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, IntegrityConflict};
use AgenToolsPlatform\Core\Context\History\DerivedKind;
use AgenToolsPlatform\Tests\Harness as H;

function fixture(callable $test):void { $f=new Fixture();try{$test($f);}finally{$f->close();} }
function stable(Fixture $f):void { H::check($f->count('responses')===1 && $f->count('runs')===1 && $f->count('invocation_intents')===0); }
P::run([
 'DEL-001'=>static function():void {fixture(static function(Fixture $f):void {
    $intent=$f->stage();H::check($f->delivery->status($f->context,$intent->key)['state']==='pending');
    $f->destination->loseAck=true;H::check($f->delivery->relay($f->context,$intent->key)['state']==='unknown');
    H::check($f->count('fixture_messages')===1);$receipt=$f->destination->lookup($f->context,$intent)->message?->value;
    $f->destination->loseAck=false;$state=$f->delivery->relay($f->context,$intent->key);
    H::check($state['state']==='delivered' && $state['receipt']===$receipt && $f->count('fixture_messages')===1);stable($f);
 });},
 'ECO-DEL-001'=>static function():void {fixture(static function(Fixture $f):void {
    $intent=$f->stage();$f->destination->unavailable=true;
    for($i=0;$i<2;++$i) { H::check($f->delivery->relay($f->context,$intent->key)['state']==='unknown'); }
    H::check($f->count('fixture_messages')===0 && $f->delivery->pending($f->context)===[$intent->key]);stable($f);
 });},
 'ECO-DEL-002'=>static function():void {fixture(static function(Fixture $f):void {
    $intent=$f->stage();$f->destination->loseAck=true;$f->delivery->relay($f->context,$intent->key);
    $f->destination->beforeCommit=static function():void {throw new RuntimeException('Recovery must use lookup, not resend');};
    $state=$f->delivery->relay($f->context,$intent->key);
    H::check($state['state']==='delivered' && $state['receipt']!==null && $f->count('fixture_messages')===1);stable($f);
 });},
 'ECO-DEL-003'=>static function():void {fixture(static function(Fixture $f):void {
    $intent=$f->stage();$f->delivery->relay($f->context,$intent->key);
    $before=$f->env->db->row('SELECT payload_hash,body FROM '.$f->env->db->table('fixture_messages'));
    try{$f->stage('Changed answer',key:$intent->key);throw new RuntimeException('Missing conflict');}
    catch(IntegrityConflict $error){H::check($error->getMessage()==='DELIVERY_PAYLOAD_CONFLICT');}
    $changed=new \AgenToolsPlatform\Postgres\Delivery\DeliverySubmission($intent->key,$intent->run,$intent->response,1,$intent->payload,
        new \AgenToolsPlatform\Core\Common\PayloadHash('sha-256:'.str_repeat('f',64)),$intent->destination,$intent->source,1,'policy-1',$intent->displayHash);
    H::rejects(fn()=>$f->destination->lookup($f->context,$changed),IntegrityConflict::class);
    H::check($before===$f->env->db->row('SELECT payload_hash,body FROM '.$f->env->db->table('fixture_messages')));stable($f);
 });},
 'ECO-DEL-004'=>static function():void {fixture(static function(Fixture $f):void {
    $intent=$f->stage();$f->permission('allowed',false);
    H::rejects(fn()=>$f->delivery->relay($f->context,$intent->key),ContractViolation::class,ErrorCode::AccessDenied);
    H::check($f->count('fixture_messages')===0);
 });},
 'ECO-DEL-005'=>static function():void {fixture(static function(Fixture $f):void {
    $intent=$f->stage();$message=$f->message();
    foreach(['summary'=>DerivedKind::Summary,'cache'=>DerivedKind::ContextCache,'index'=>DerivedKind::HistoryIndex,'candidate'=>DerivedKind::Candidate] as $id=>$kind) {
        $f->graph->register($f->context,$f->ref($id),$kind,[$message],[$f->ack->runId]);H::check($f->graph->usable($f->context,$f->ref($id)));
    }
    $f->host->sourceAllowed=false;
    $f->invalidation->apply($f->context,$intent->source,$message,2,'source-delete-1','source_deleted');
    $f->invalidation->apply($f->context,$intent->source,$message,2,'source-delete-1','source_deleted');
    foreach(['summary','cache','index','candidate'] as $id){H::check(!$f->graph->usable($f->context,$f->ref($id)));}
    H::check($f->delivery->pending($f->context)===[] && $f->count('fixture_messages')===0 && $f->count('source_invalidation_receipts')===1);
    $technical=$f->env->db->row('SELECT checkpoint FROM '.$f->env->db->table('runs'));
    H::check(!str_contains(json_encode($technical,JSON_THROW_ON_ERROR),'Synthetic source, held only by destination'));
 });},
 'V2-P10-2-DELIVERY-POS'=>static function():void {fixture(static function(Fixture $f):void {
    $intent=$f->stage();H::check($f->count('responses')===1 && $f->count('delivery_intents')===1 && $f->count('fixture_messages')===0);
    $state=$f->delivery->relay($f->context,$intent->key);H::check($state['state']==='delivered' && $state['receipt']!==null);stable($f);
 });},
 'V2ALL-DELIVERY-POSITIVE'=>static function():void {fixture(static function(Fixture $f):void {
    $intent=$f->stage();H::check($f->delivery->pending($f->context,1)===[$intent->key]);
    $one=$f->delivery->relay($f->context,$intent->key);$two=$f->delivery->relay($f->context,$intent->key);
    H::check($one===$two && $two['state']==='delivered' && $f->count('fixture_messages')===1 && $f->count('delivery_receipts')===1);stable($f);
 });},
 'V2ALL-DELIVERY-NEGATIVE'=>static function():void {
    foreach(['visible'=>false,'held'=>true,'relay'=>'another-relay'] as $field=>$value){fixture(static function(Fixture $f)use($field,$value):void {
        $intent=$f->stage();$f->permission($field,$value);
        H::rejects(fn()=>$f->delivery->relay($f->context,$intent->key),ContractViolation::class,ErrorCode::AccessDenied);
        H::check($f->count('fixture_messages')===0);stable($f);
    });}
 },
 'V2ALL-DELIVERY-FAULT'=>static function():void {fixture(static function(Fixture $f):void {
    $intent=$f->stage();$f->destination->loseAck=true;$f->delivery->relay($f->context,$intent->key);
    $f->destination->unavailable=true;H::check($f->delivery->relay($f->context,$intent->key)['state']==='unknown');
    $f->destination->unavailable=false;$state=$f->delivery->relay($f->context,$intent->key);
    H::check($state['state']==='delivered' && $f->count('fixture_messages')===1);stable($f);
 });},
 'chatom.owner-permission-race'=>static function():void {fixture(static function(Fixture $f):void {
    $intent=$f->stage();$f->destination->beforeCommit=static function()use($f):void {$f->permission('visible',false);};
    H::rejects(fn()=>$f->delivery->relay($f->context,$intent->key),ContractViolation::class,ErrorCode::AccessDenied);
    H::check($f->count('fixture_messages')===0);
 });},
 'chatom.tenant-isolation'=>static function():void {fixture(static function(Fixture $f):void {
    $intent=$f->stage();$other=new Fixture($f->schema,'tenant-b');
    H::rejects(fn()=>$other->delivery->status($other->context,$intent->key),ContractViolation::class);
    H::rejects(fn()=>$other->delivery->submit($other->context,$intent),ContractViolation::class);
    $own=$other->stage();H::check($own->key!==$intent->key);
    H::check($other->delivery->relay($other->context,$own->key)['state']==='delivered');
    H::check($f->delivery->status($f->context,$intent->key)['state']==='pending');
 });},
 'chatom.cancel-retains-unknown'=>static function():void {fixture(static function(Fixture $f):void {
    $intent=$f->stage();$f->destination->loseAck=true;$f->delivery->relay($f->context,$intent->key);
    $f->delivery->cancel($f->context,$intent->key,'user_cancelled');$state=$f->delivery->relay($f->context,$intent->key);
    H::check($state['state']==='unknown' && $state['cancelled'] && $f->count('fixture_messages')===1);
 });},
]);
