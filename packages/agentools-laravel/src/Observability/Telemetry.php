<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Laravel\Observability;

use AgenToolsPlatform\Core\Common\{CanonicalHasher,Ref};
use AgenToolsPlatform\Core\Observability\{AuditSink,Event,Metric,MetricSink,Observer,Outcome,Signal,Stage};
use AgenToolsPlatform\Core\Security\{EntryPoint,ExecutionContext,TraceLinks};
use AgenToolsPlatform\Core\Models\{ModelRequest,Deployment};
use AgenToolsPlatform\Laravel\Managed\{Entry,HostBindings,ProductionBinding,Work};

/** Scoped per-job trace context; finally reset clears it. No payload, credential or exception cache. */
final class Telemetry implements ProductionBinding
{
    private Observer $observer;
    private ?ExecutionContext $context=null;
    private ?Entry $entry=null;
    private ?string $attempt=null;
    private int $sequence=0;
    private ?int $last=null;
    public function __construct(private readonly CanonicalHasher $hasher,private readonly AuditSink $audit,
        private readonly ?MetricSink $metrics=null,bool $metricsRequired=false)
    { $this->observer=new Observer($audit,$metrics,$metricsRequired); }
    public function assertProduction(): void
    {
        HostBindings::assertProductionService($this->audit);
        if ($this->metrics!==null) { HostBindings::assertProductionService($this->metrics); }
    }
    /** Timings are local monotonic stage intervals; no production threshold is inferred. */
    public function record(ExecutionContext $context,Entry $entry,Stage $stage,Outcome $outcome,TraceLinks $links): void
    {
        if ($this->context!==null) { $this->context->session->assertSame($context->session); }
        $this->context=$context;$this->entry=$entry;
        $now=hrtime(true); $duration=$this->last===null?null:($now-$this->last)/1_000_000; $this->last=$now;
        $signal=match($stage) { Stage::Context=>Signal::CompilerLatency,Stage::Provider=>Signal::ProviderLatency,
            Stage::Action=>Signal::ActionLatency,Stage::Tool=>Signal::ToolLatency,Stage::Delivery=>Signal::DeliveryLatency,
            Stage::Recovery=>Signal::ReconcileLatency,default=>Signal::RunLatency };
        $this->observer->emit($this->event($context,$entry,$stage,$outcome,$links),[
            new Metric($signal,$duration,$stage,$outcome,self::entry($entry),certainty:$duration===null?'unknown':'measured')]);
    }
    public function provider(#[\SensitiveParameter] ModelRequest $request,Deployment $deployment,Outcome $outcome,?float $duration=null,#[\SensitiveParameter] ?\Throwable $error=null): void
    {
        $context=$this->context;$entry=$this->entry;
        if ($context===null || $entry===null) { throw new \AgenToolsPlatform\Core\Observability\Failure('reset_failed'); }
        $tenant=$context->session->tenant;$tenant->assertSame($request->invocationId->tenant);
        $links=new TraceLinks(snapshot:$request->contextSnapshotRef,invocation:new Ref($tenant,$request->invocationId->value),model:$deployment->ref,hash:$request->contextSnapshotHash);
        $this->observer->emit($this->event($context,$entry,Stage::Provider,$outcome,$links,$error),[
            new Metric(Signal::ProviderLatency,$duration,Stage::Provider,$outcome,self::entry($entry),certainty:$duration===null?'unknown':'measured')]);
    }
    public function failure(ExecutionContext $context,Entry $entry,#[\SensitiveParameter] \Throwable $error): void
    {
        $this->observer->emit($this->event($context,$entry,Stage::Runtime,Outcome::Failed,new TraceLinks(),$error));
    }
    private function event(ExecutionContext $context,Entry $entry,Stage $stage,Outcome $outcome,TraceLinks $links,?\Throwable $error=null): Event
    {
        $this->attempt??=bin2hex(random_bytes(16));
        $id=new Ref($context->session->tenant,'trace:'.$this->attempt.':'.(++$this->sequence));
        return new Event($id,$context,$stage,$outcome,self::entry($entry),$links,$this->hasher,error:$error);
    }
    public static function entry(Entry $entry): EntryPoint
    {
        return match($entry) { Entry::Raw=>EntryPoint::Raw,Entry::Build=>EntryPoint::Build,Entry::Stream=>EntryPoint::Stream,
            Entry::Voice=>EntryPoint::Voice,Entry::Background=>EntryPoint::Background,default=>EntryPoint::Text };
    }
    /** @return array{healthy:bool,metrics_degraded:bool} */
    public function health(): array { return $this->observer->health(); }
    public function reset(): void
    {
        try { $this->observer->reset(); }
        finally { $this->attempt=null;$this->sequence=0;$this->last=null;$this->context=null;$this->entry=null; }
    }
    /** @return array{healthy:bool,metrics_degraded:bool} */
    public function __debugInfo(): array { return $this->health(); }
}
