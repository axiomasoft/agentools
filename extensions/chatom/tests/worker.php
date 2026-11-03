<?php

declare(strict_types=1);
require_once __DIR__.'/Fixture.php';
use AgenToolsPlatform\ChatomBridge\Tests\Fixture;

$mode=$argv[1]??'';$schema=$argv[2]??'';
$f=new Fixture($schema);
$backend=(int)$f->env->db->execute('SELECT pg_backend_pid()')->fetchColumn();
echo json_encode(['backend'=>$backend],JSON_THROW_ON_ERROR).PHP_EOL;flush();fgets(STDIN);
$intent=$f->stage();
$pause=static function(array $point):void {echo json_encode($point,JSON_THROW_ON_ERROR).PHP_EOL;flush();fgets(STDIN);};
if($mode==='claim'){
    $claim=$f->store->claim($f->context,$intent->key,1000);
    echo json_encode(['claimed'=>$claim!==null,'epoch'=>$claim?->epoch],JSON_THROW_ON_ERROR).PHP_EOL;
}elseif($mode==='commit'){
    $pause(['point'=>'response-intent-committed','key'=>$intent->key]);
}elseif($mode==='destination'){
    $f->destination->afterCommit=static function()use($pause,$intent):void{$pause(['point'=>'destination-committed','key'=>$intent->key]);};
    $f->delivery->relay($f->context,$intent->key);
}elseif($mode==='recover'){
    $state=$f->delivery->relay($f->context,$intent->key);
    echo json_encode(['state'=>$state,'messages'=>$f->count('fixture_messages'),'responses'=>$f->count('responses'),'invocations'=>$f->count('invocation_intents')],JSON_THROW_ON_ERROR).PHP_EOL;
}elseif($mode==='closure'){
    $source=$intent->source;$message=$f->message();$hash=$f->hasher->hash($source->tenant,'chatom-source-event',[$source->toArray(),$message->toArray(),2,'source_deleted']);
    $f->store->closeSource($f->context,$source,2,'delete-recovery',$hash);
    $pause(['point'=>'source-closed']);
}else{throw new RuntimeException('Unknown worker mode');}
