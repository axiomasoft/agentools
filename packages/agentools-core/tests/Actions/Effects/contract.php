<?php

declare(strict_types=1);

require_once dirname(__DIR__,5).'/examples/platform/confirmed-write/bootstrap.php';
require_once dirname(__DIR__).'/Fixtures.php';
require_once dirname(__DIR__,4).'/agentools-testing/src/Owners/ScriptedOwner.php';

use AgenTools\Action\{ActionEvent, ActionReducer, Actor, FoldedAction, JournalHashChain};
use AgenTools\Schema\Canonicalizer;
use AgenToolsPlatform\Core\Actions\{ActionView};
use AgenToolsPlatform\Core\Actions\Effects\{LookupResult, LookupStatus, Request, Strategy};
use AgenToolsPlatform\Core\Common\{ActionId, ActionReceipt, IntegrityConflict, PayloadHash, Ref, TenantId};
use AgenToolsPlatform\Testing\Owners\ScriptedOwner;
use AgenToolsPlatform\Tests\{Harness as H, TestHost};
use AgenToolsPlatform\Tests\Actions\Fixtures as F;

$root=dirname(__DIR__,5);
$tenant=new TenantId('tenant-a');
$context=F::context(new TestHost($tenant));
$hash=new PayloadHash('sha-256:'.str_repeat('a',64));
$make=static fn (PayloadHash $h): Request=>new Request(new ActionId($tenant,'action'),'effect','test.scripted-owner','operation',$h,
    new Ref($tenant,'payload:ref'),$context,1,Strategy::NativeKey);
$cases=[
    'effect.strategy-matrix'=>static function () use ($root): void {
        /** @var array{cases:list<array{strategy:string,lookup:string,retry:bool}>} $fixture */
        $fixture=json_decode((string)file_get_contents($root.'/fixtures/platform/v1/effects/strategies.json'),true,512,JSON_THROW_ON_ERROR);
        H::check(count($fixture['cases'])===15);
        foreach ($fixture['cases'] as $row) { H::check(Strategy::from($row['strategy'])->takeoverDecision(LookupStatus::from($row['lookup']))===$row['retry']); }
    },
    'effect.typed-binding'=>static function () use ($tenant,$make,$hash): void {
        $request=$make($hash);
        $result=new LookupResult(LookupStatus::Succeeded,new Ref($tenant,'operation'),'test.scripted-owner',$hash,new Ref($tenant,'receipt'));
        $result->assertRequest($request);
        H::rejects(static fn ()=>$result->assertRequest($make(new PayloadHash('sha-256:'.str_repeat('b',64)))),IntegrityConflict::class);
        H::rejects(static fn ()=>new LookupResult(LookupStatus::Succeeded,new Ref($tenant,'operation'),'test.scripted-owner',$hash),InvalidArgumentException::class);
        H::rejects(static fn ()=>new LookupResult(LookupStatus::Absent,new Ref($tenant,'operation'),'test.scripted-owner',$hash),InvalidArgumentException::class);
        H::rejects(static fn ()=>(new LookupResult(LookupStatus::Unknown,new Ref(new TenantId('tenant-b'),'operation'),'test.scripted-owner',$hash))->assertRequest($request),\Throwable::class);
    },
    'effect.native-key-contract'=>static function () use ($make,$hash): void {
        $owner=new ScriptedOwner();
        $request=$make($hash);
        $first=$owner->execute($request);
        H::check($first===$owner->execute($request));
        H::check($first===$owner->lookup($request));
        H::rejects(static fn ()=>$owner->execute($make(new PayloadHash('sha-256:'.str_repeat('b',64)))),IntegrityConflict::class);
    },
];
/** @var array{cases:list<array{id:string,events:list<array{kind:string,payload?:array<string,mixed>,epoch?:int}>,status:string,outcome:string}>} $fixture */
$fixture=json_decode((string)file_get_contents($root.'/fixtures/platform/v1/effects/outcomes.json'),true,512,JSON_THROW_ON_ERROR);
H::check(array_column($fixture['cases'],'id')===['effect.pending','effect.unknown','effect.success','effect.failure','effect.takeover']);
foreach ($fixture['cases'] as $item) {
    $cases['php.'.$item['id']]=static function () use ($tenant,$item): void {
        $state=new FoldedAction(); $reducer=new ActionReducer(hashChain:new JournalHashChain(new Canonicalizer()));
        foreach ($item['events'] as $event) {
            $state=$reducer->apply($state,new ActionEvent('event-'.($state->seq+1),'dated-action',$state->seq+1,$state->seq,
                '2026-11-01T00:00:00Z',new Actor('host','owner'),$event['kind'],1,'2026-09',$event['payload']??[], $event['epoch']??null,$state->previousHash));
        }
        H::check($state->status?->value===$item['status']);
        $view=new ActionView(new ActionReceipt(new ActionId($tenant,'dated-action'),$item['status'],$state->seq),F::definition()->ref,'status');
        H::check($view->outcome()?->value===$item['outcome']);
        H::check(count($state->effects)===1);
    };
}
H::run($cases,'contract-scripted','contract-scripted');
