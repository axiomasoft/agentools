<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use AgenToolsPlatform\Tests\Context\History\Fixture;

$f=new Fixture();$summary=$f->summary();$root=dirname(__DIR__,5).'/fixtures/platform/v1/history';
foreach (['working-state.json'=>$f->state->toArray(),'summary.json'=>$summary->toArray(),'memory-candidate.json'=>$f->candidate()->toArray()] as $name=>$value) {
    if (file_put_contents($root.'/'.$name,json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n")===false) { throw new RuntimeException('Fixture generation failed'); }
    echo $name.PHP_EOL;
}
