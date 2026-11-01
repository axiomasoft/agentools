<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use AgenToolsPlatform\Core\Context\{BudgetOverflow, Provenance, SnapshotPins, SourceKind};
use AgenToolsPlatform\Tests\Context\Fixture;
use AgenToolsPlatform\Tests\Harness as H;

H::run(['context.generated-examples'=>static function(): void {
    $f=new Fixture(); $root=dirname(__DIR__,4).'/fixtures/platform/v1/context';
    $load=static function(string $name) use($root): array {
        $wire=file_get_contents($root.'/'.$name); if ($wire === false) { throw new RuntimeException('Required fixture missing'); }
        return Fixture::object(json_decode($wire,true,64,JSON_THROW_ON_ERROR));
    };
    $plan=$f->plan([$f->block('history','recent','Exact previous turn',true,provenance:new Provenance(SourceKind::History,
        [$f->ref('message:7')],$f->ref('turn:7'),'summary:v1',$f->now))],
        new SnapshotPins([['ref'=>$f->ref('skill:v1'),'version'=>'v1','hash'=>$f->bundle->hash]],$f->ref('route:v1'),['temperature'=>0]));
    $text=$f->compiler->finalize($plan,$f->target(),$f,$f->now,$f);
    $voice=$f->compiler->finalize($plan,$f->target(),$f,$f->now,$f);
    $expected=$load('snapshot.json');
    H::check($expected === Fixture::object(json_decode($text->manifestJson,true,64,JSON_THROW_ON_ERROR)));
    H::check($load('provenance.json') === $plan->blocks[1]->manifest());
    $equivalence=$load('text-voice.json');
    H::check($equivalence['text_snapshot'] === $text->snapshot->toArray() && $equivalence['voice_decoded_delta_snapshot'] === $voice->snapshot->toArray());
    $f->forcedCount=5600;
    try { $f->compiler->finalize($f->plan(),$f->target(),$f,$f->now,$f); throw new RuntimeException('Overflow accepted'); }
    catch (BudgetOverflow $e) { H::check($load('overflow.json') === $e->report); }
}]);
