<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use AgenToolsPlatform\Core\Context\{BudgetOverflow, Provenance, SnapshotPins, SourceKind};
use AgenToolsPlatform\Tests\Context\Fixture;

$f=new Fixture();
$plan=$f->plan([$f->block('history','recent','Exact previous turn',true,provenance:new Provenance(SourceKind::History,
    [$f->ref('message:7')],$f->ref('turn:7'),'summary:v1',$f->now))],
    new SnapshotPins([['ref'=>$f->ref('skill:v1'),'version'=>'v1','hash'=>$f->bundle->hash]],$f->ref('route:v1'),['temperature'=>0]));
$result=$f->compiler->finalize($plan,$f->target(),$f,$f->now,$f);
$examples=['snapshot.json'=>json_decode($result->manifestJson,true,64,JSON_THROW_ON_ERROR),
    'provenance.json'=>$plan->blocks[1]->manifest(),
    'text-voice.json'=>['text_snapshot'=>$result->snapshot->toArray(),'voice_decoded_delta_snapshot'=>$result->snapshot->toArray(),
        'contract'=>'Same pinned plan, adapters and decoded input; no second trust model or live voice qualification.']];
$f->forcedCount=5600;
try { $f->compiler->finalize($f->plan(),$f->target(),$f,$f->now,$f); throw new RuntimeException('Expected overflow'); }
catch (BudgetOverflow $error) { $examples['overflow.json']=$error->report; }
$root=dirname(__DIR__,4).'/fixtures/platform/v1/context';
if (!is_dir($root) && !mkdir($root,0777,true) && !is_dir($root)) { throw new RuntimeException('Fixture directory unavailable'); }
foreach ($examples as $name=>$value) {
    if (file_put_contents($root.'/'.$name,json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n") === false) { throw new RuntimeException('Fixture generation failed'); }
    echo $name."\n";
}
