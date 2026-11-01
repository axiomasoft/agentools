<?php

declare(strict_types=1);

require_once dirname(__DIR__,2).'/vendor/autoload.php';
require_once __DIR__.'/PlatformBindingFixture.php';

use AgenTools\Tests\Confirmation\PlatformBindingFixture;

$json=file_get_contents(dirname(__DIR__,3).'/fixtures/platform/v1/confirmation/bindings.json');
if ($json===false) { throw new RuntimeException('Required confirmation fixtures missing'); }
/** @var array{arguments:array<string,mixed>,cases:list<array{id:string}>} $data */
$data=json_decode($json,true,512,JSON_THROW_ON_ERROR);
$expected=['recipient','document','amount','caller-flags','scope','definition','policy','actor','tenant','expired','replay','missing-proof','forged-voice'];
if (array_column($data['cases'],'id')!==$expected) { throw new RuntimeException('Required confirmation case set changed'); }
$only=null;
foreach ($argv as $arg) { if (str_starts_with($arg,'--only=')) { $only=explode(',',substr($arg,7)); } }
$cases=[];
foreach ($data['cases'] as $case) {
    if ($only!==null && !in_array($case['id'],$only,true)) { continue; }
    $id=match($case['id']) { 'recipient'=>'CNF-001','caller-flags'=>'CNF-003',default=>'php.confirmation.'.$case['id'] };
    $cases[]=['id'=>$id,'status'=>PlatformBindingFixture::rejectsCase($data['arguments'],$case)?'passed':'failed'];
}
$failures=count(array_filter($cases,static fn (array $case): bool=>$case['status']!=='passed'));
echo json_encode(['mode'=>'contract-scripted','tier'=>'contract-scripted','cases'=>$cases,'count'=>count($cases),'failures'=>$failures,'skips'=>0],JSON_THROW_ON_ERROR)."\n";
exit($cases!==[] && $failures===0?0:1);
