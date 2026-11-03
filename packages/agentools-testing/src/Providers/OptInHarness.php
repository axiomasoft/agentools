<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Testing\Providers;
use AgenToolsPlatform\Core\Common\UtcInstant;
use AgenToolsPlatform\Core\Models\{Deployment,ModelRequest,ModelResponse,ModelTransport,StreamState,TransportPreflight};

/** Explicit one-exchange probes, no fallback/retry/effect loop and no automatic qualification. */
final class OptInHarness
{
    private int $calls=0;
    private int $reservedMicros=0;
    /** @var array<string,bool> */ private array $attempted=[];
    /** @param \Closure(): UtcInstant $clock */
    public function __construct(private readonly ModelTransport $transport,private readonly ProbeBudget $budget,private readonly ProbePolicy $policy,private readonly \Closure $clock) {}
    public function generate(ModelRequest $request,Deployment $deployment): ModelResponse
    {
        $ceiling=$this->prepare($request,$deployment,false); $reservation=$this->budget->reserve($request,$deployment,$ceiling);
        $this->reservedMicros+=$ceiling;
        try {
            $this->budget->assertCurrent($request,$deployment); $this->prepare($request,$deployment,false,false);
            $this->attempted[$this->identity($request)]=true; ++$this->calls;
            $response=$this->transport->generate($request,$deployment); $response->assertFor($request,$deployment);
        }
        catch (\Throwable) { $this->budget->record($reservation,$request,null); throw new \RuntimeException('PROBE_OUTCOME_UNKNOWN'); }
        $this->budget->record($reservation,$request,$response); return $response;
    }
    public function stream(ModelRequest $request,Deployment $deployment): StreamState
    {
        $ceiling=$this->prepare($request,$deployment,true); $reservation=$this->budget->reserve($request,$deployment,$ceiling);
        $this->reservedMicros+=$ceiling;
        $state=new StreamState($request->invocationId);
        try {
            $this->budget->assertCurrent($request,$deployment); $this->prepare($request,$deployment,true,false);
            $this->attempted[$this->identity($request)]=true; ++$this->calls;
            foreach ($this->transport->stream($request,$deployment) as $event) { $this->budget->assertCurrent($request,$deployment); $state->accept($event); }
            $state->terminal()?->assertFor($request,$deployment);
        }
        catch (\Throwable) { $this->budget->record($reservation,$request,null); throw new \RuntimeException('PROBE_STREAM_UNKNOWN'); }
        $this->budget->record($reservation,$request,$state->terminal()); return $state;
    }
    /** @return array{provider_support:string,educational_qualification:string,calls:int} */
    public function report(): array { return ['provider_support'=>'not_qualified','educational_qualification'=>'insufficient_evidence','calls'=>$this->calls]; }
    private function prepare(ModelRequest $request,Deployment $deployment,bool $stream,bool $capacity=true): int
    {
        if ($this->calls>=$this->policy->maxCalls || isset($this->attempted[$this->identity($request)])) { throw new \LogicException('Probe call quota/identity denied'); }
        $this->policy->testCredential->assertSame($request->credentialRef); $this->budget->assertCurrent($request,$deployment);
        $pin=$this->policy->deployment; $pin->ref->assertSame($deployment->ref);
        if (!$pin->sameBinding($deployment) || $pin->model!==$deployment->model) { throw new \LogicException('Probe quote/deployment binding changed'); }
        TransportPreflight::check($request,$deployment,($this->clock)(),$stream);
        if ($request->cache->mode!==\AgenToolsPlatform\Core\Models\CacheMode::Bypass || $request->continuation!==null) { throw new \LogicException('Live probe scope excludes persistent provider storage'); }
        foreach ($request->messages as $message) { foreach ($message->parts as $part) { if ($part->ref!==null) { throw new \LogicException('Live probe scope excludes external payload bytes'); } } }
        $data=get_object_vars($request); unset($data['cancellation']); $json=json_encode($data,JSON_THROW_ON_ERROR); $bytes=strlen($json);
        if (!hash_equals($this->policy->approvedPayloadHash,ProbePolicy::payloadHash($request))) { throw new \LogicException('Probe data differs from explicitly approved payload'); }
        $bound=$this->policy->ceiling($bytes,$request->generation->maxOutputTokens,($this->clock)());
        if ($capacity && $bound>$this->policy->ceilingMicros-$this->reservedMicros) { throw new \LogicException('Probe cumulative spend ceiling exceeded'); }
        return $bound;
    }
    private function identity(ModelRequest $request): string { return $request->invocationId->tenant->value.':'.$request->invocationId->value; }
}
