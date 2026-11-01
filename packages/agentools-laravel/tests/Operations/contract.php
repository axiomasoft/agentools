<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/bootstrap.php';
require_once dirname(__DIR__,3).'/agentools-core/tests/Actions/Fixtures.php';

use AgenTools\Action\{ActionEvent, ActionReducer, Actor, FoldedAction, JournalHashChain};
use AgenTools\Schema\Canonicalizer;
use AgenToolsPlatform\Core\Actions\Cancellation\{InterruptedOutput, Operation};
use AgenToolsPlatform\Core\Common\{ContractViolation, PayloadHash, Ref, RunId, RunStatus, TenantId};
use AgenToolsPlatform\Core\Runtime\RunCancellation;
use AgenToolsPlatform\Tests\{Harness as H, TestHost};
use AgenToolsPlatform\Tests\Actions\Fixtures as F;

$tenant=new TenantId('tenant-a'); $context=F::context(new TestHost($tenant));
$hasher=new \AgenToolsPlatform\Core\Common\CanonicalHasher(new \AgenToolsPlatform\Tests\JcsAdapter());
$make=static fn (string $kind='run_cancel',int $version=0,?Ref $evidence=null,string $reason='requested_stop'): Operation=>new Operation(
    new Ref($tenant,'command'),$kind,new RunId($tenant,'run'),$version,0,$reason,$evidence,new PayloadHash('sha-256:'.str_repeat('a',64)),$context,$hasher);
$cases=[
    'cancellation.command-binding'=>static function () use ($make): void {
        H::check($make()->hash->value===$make()->hash->value);
        H::check($make()->hash->value!==$make(version:1)->hash->value);
        H::check($make()->hash->value!==$make(reason:'other_reason')->hash->value);
        H::rejects(static fn ()=>$make(reason:'raw text payload'),InvalidArgumentException::class);
        H::rejects(static fn ()=>$make(kind:'run_finalize'),InvalidArgumentException::class);
    },
    'cancellation.run-policy'=>static function () use ($make,$tenant): void {
        $policy=new RunCancellation();
        foreach (RunStatus::cases() as $status) {
            H::check($policy->next($status,$make(),true)===($status->terminal()?$status:RunStatus::Cancelling));
        }
        $finish=$make(kind:'run_finalize',evidence:new Ref($tenant,'policy:verified'));
        H::rejects(static fn ()=>$policy->next(RunStatus::Cancelling,$finish,true),ContractViolation::class);
        H::rejects(static fn ()=>$policy->next(RunStatus::Running,$finish,false),ContractViolation::class);
        H::check($policy->next(RunStatus::Cancelling,$finish,false)===RunStatus::Cancelled);
    },
    'cancellation.output-certainty'=>static function () use ($tenant): void {
        $output=new Ref($tenant,'delivery'); $receipt=new Ref($tenant,'receipt');
        $unknown=(new InterruptedOutput($output,'tts','audio_frames',null,100,null))->toArray();
        H::check($unknown['acknowledged_units']===null && $unknown['acknowledgement']==='unknown');
        $zero=(new InterruptedOutput($output,'stream','chunks',0,3,$receipt))->toArray();
        H::check($zero['acknowledged_units']===0 && $zero['acknowledgement']==='known');
        H::rejects(static fn ()=>new InterruptedOutput($output,'stream','chunks',3,2,$receipt),InvalidArgumentException::class);
        H::rejects(static fn ()=>new InterruptedOutput($output,'stream','chunks',1,2,null),InvalidArgumentException::class);
    },
];
/** @var array{cases:list<array{id:string,events:list<array{kind:string,payload?:array<string,mixed>,epoch?:int}>,status:string,effect:?string,cancelled:bool}>} $fixture */
$fixture=json_decode((string)file_get_contents(dirname(__DIR__,4).'/fixtures/platform/v1/cancellation/outcomes.json'),true,512,JSON_THROW_ON_ERROR);
H::check(array_column($fixture['cases'],'id')===['cancel.unknown','cancel.late-success','cancel.completed-stop','cancel.before-io']);
foreach ($fixture['cases'] as $row) {
    $cases['php.'.$row['id']]=static function () use ($row): void {
        $state=new FoldedAction(); $reducer=new ActionReducer(hashChain:new JournalHashChain(new Canonicalizer()));
        foreach ($row['events'] as $event) {
            $state=$reducer->apply($state,new ActionEvent('event-'.($state->seq+1),'action',$state->seq+1,$state->seq,
                '2026-11-01T00:00:00Z',new Actor('host','operator'),$event['kind'],1,'2026-09',$event['payload']??[],$event['epoch']??null,$state->previousHash));
        }
        H::check($state->status?->value===$row['status']);
        H::check(($state->effects['e1']->status->value??null)===$row['effect']);
        H::check($state->cancellationRequested===$row['cancelled']);
    };
}
H::run($cases,'contract-scripted','contract-scripted');
