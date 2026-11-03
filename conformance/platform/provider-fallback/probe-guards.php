<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
use AgenToolsPlatform\Conformance\Providers\Proof;
use AgenToolsPlatform\Core\Common\{InvocationId,Ref};
use AgenToolsPlatform\Core\Models\{Deployment,GenerationOptions,ModelRequest,ModelResponse};
use AgenToolsPlatform\ModelAdapterTests\Fixture as F;
use AgenToolsPlatform\Testing\Providers\{OptInHarness,ProbeBudget,ProbePolicy,ScriptedTransport};
use AgenToolsPlatform\Tests\Harness as H;

final class ProbeOwner implements ProbeBudget
{
    public bool $allowed=true; public int $reservations=0; public ?Closure $afterReserve=null;
    /** @var array<string,bool> */ private array $attempted=[];
    /** @var list<string> */ public array $exposure=[];
    public function assertCurrent(ModelRequest $request,Deployment $deployment): void { if (!$this->allowed) { throw new LogicException('Current owner permission denied'); } }
    public function reserve(ModelRequest $request,Deployment $deployment,int $ceilingMicros): Ref
    {
        $id=$request->invocationId->value; if (isset($this->attempted[$id])) { throw new LogicException('Durable probe identity already attempted'); }
        $this->attempted[$id]=true; ++$this->reservations; if ($this->afterReserve!==null) { ($this->afterReserve)(); } return F::ref('reservation:'.$id);
    }
    public function record(Ref $reservation,ModelRequest $request,?ModelResponse $response): void
    { $this->exposure[]=$response===null || $response->finishReason===\AgenToolsPlatform\Core\Models\FinishReason::Unknown?'unknown':'known'; }
}
function probeRequest(F $f,string $id='probe-1',?string $text=null): ModelRequest
{
    $r=$f->request(tools:false);
    return new ModelRequest(new InvocationId($r->invocationId->tenant,$id),$text===null?$r->messages:[new \AgenToolsPlatform\Core\Models\ModelMessage(
        \AgenToolsPlatform\Core\Models\MessageRole::User,[\AgenToolsPlatform\Core\Models\ContentPart::text($text)])],
        $r->contextSnapshotRef,$r->contextSnapshotHash,$r->credentialRef,$r->deadline,$r->cancellation,$r->system,$r->policy,generation:new GenerationOptions(maxOutputTokens:16));
}
function probePolicy(ModelRequest $r,bool $enabled=true,?int $inputPrice=1,int $ceiling=100000,int $calls=2,?int $dataBytes=null): ProbePolicy
{
    return new ProbePolicy($r->credentialRef,F::ref('owner-explicit-test-data-permission'),$enabled,true,$calls,$dataBytes ?? 8192,16,$ceiling,
        $inputPrice,1,'USD','host-test-upper-bound',F::ref('owner-price-evidence'),$r->deadline,$r->deadline,ProbePolicy::payloadHash($r),(new F())->deployment());
}

Proof::run([
 'probe.offline-and-unknown-price-fail-closed'=>static function (): void {
    foreach (['disabled','unknown-price','ceiling','data'] as $mode) {
        $f=new F(); $r=probeRequest($f); $http=F::jsonHttp(F::message()); $owner=new ProbeOwner();
        $policy=probePolicy($r,$mode!=='disabled',$mode==='unknown-price'?null:1,$mode==='ceiling'?0:100000,2,$mode==='data'?1:8192);
        $probe=new OptInHarness($f->transport($http),$owner,$policy,$f->clock->now(...));
        H::rejects(fn ()=>$probe->generate($r,$f->deployment()),LogicException::class); H::check(count($http->requests)===0 && $owner->reservations===0);
    }
 },
 'probe.explicit-data-credential-and-current-owner'=>static function (): void {
    $f=new F(); $r=probeRequest($f); $http=F::jsonHttp(F::message()); $owner=new ProbeOwner();
    $probe=new OptInHarness($f->transport($http),$owner,probePolicy($r),$f->clock->now(...));
    H::rejects(fn ()=>$probe->generate(probeRequest($f,text:'unapproved/private content'),$f->deployment()),LogicException::class);
    $wrong=new ModelRequest($r->invocationId,$r->messages,$r->contextSnapshotRef,$r->contextSnapshotHash,F::ref('not-the-test-credential'),$r->deadline,
        $r->cancellation,$r->system,$r->policy,generation:$r->generation);
    H::rejects(fn ()=>$probe->generate($wrong,$f->deployment()),\AgenToolsPlatform\Core\Common\ContractViolation::class,\AgenToolsPlatform\Core\Common\ErrorCode::IntegrityConflict);
    $d=$f->deployment(); $other=new Deployment($d->ref,$d->provider,$d->account,$d->region,'unapproved-price-model',$d->capabilities);
    H::rejects(fn ()=>$probe->generate($r,$other),LogicException::class);
    $owner->afterReserve=function () use ($owner): void { $owner->allowed=false; };
    H::rejects(fn ()=>$probe->generate($r,$f->deployment()),RuntimeException::class);
    H::check(count($http->requests)===0 && $owner->exposure===['unknown']);
 },
 'probe.calls-and-cumulative-spend-bounded'=>static function (): void {
    $f=new F(); $r=probeRequest($f); $data=get_object_vars($r); unset($data['cancellation']); $bytes=strlen(json_encode($data,JSON_THROW_ON_ERROR));
    $owner=new ProbeOwner(); $http=F::jsonHttp(F::message());
    $probe=new OptInHarness($f->transport($http),$owner,probePolicy($r,ceiling:$bytes+16),$f->clock->now(...));
    $probe->generate($r,$f->deployment()); H::rejects(fn ()=>$probe->generate(probeRequest($f,'probe-2'),$f->deployment()),LogicException::class);
    H::check(count($http->requests)===1 && $owner->reservations===1 && $probe->report()['provider_support']==='not_qualified');
 },
 'probe.unknown-no-blind-resend'=>static function (): void {
    $f=new F(); $r=probeRequest($f); $owner=new ProbeOwner();
    $transport=new ScriptedTransport(static function (): ModelResponse { throw new RuntimeException('secret provider reply'); },static fn (): iterable=>[], $f->clock->now(...));
    $policy=probePolicy($r); $probe=new OptInHarness($transport,$owner,$policy,$f->clock->now(...));
    H::rejects(fn ()=>$probe->generate($r,$f->deployment()),RuntimeException::class);
    H::rejects(fn ()=>$probe->generate($r,$f->deployment()),LogicException::class);
    $restarted=new OptInHarness($transport,$owner,$policy,$f->clock->now(...)); H::rejects(fn ()=>$restarted->generate($r,$f->deployment()),LogicException::class);
    H::check($transport->calls===1 && $owner->exposure===['unknown']);
 },
 'probe.partial-stream-remains-unknown'=>static function (): void {
    $f=new F(); $r=probeRequest($f); $owner=new ProbeOwner();
    $transport=new ScriptedTransport(static function (): ModelResponse { throw new LogicException('Not used'); },static function ($request): iterable {
        yield new \AgenToolsPlatform\Core\Models\ModelEvent($request->invocationId,1,\AgenToolsPlatform\Core\Models\EventKind::Started);
        yield new \AgenToolsPlatform\Core\Models\ModelEvent($request->invocationId,2,\AgenToolsPlatform\Core\Models\EventKind::TextDelta,delta:'partial');
    },$f->clock->now(...));
    $probe=new OptInHarness($transport,$owner,probePolicy($r),$f->clock->now(...)); $state=$probe->stream($r,$f->deployment());
    H::check($state->isUnknown() && $state->text()==='partial' && $owner->exposure===['unknown'] && $probe->report()['educational_qualification']==='insufficient_evidence');
 },
]);
