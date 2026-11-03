<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Core\Observability;

use AgenToolsPlatform\Core\Common\Ref;

/** Holds only worker health, never tenant refs, exception objects, plaintext or pending batches. */
final class Observer
{
    private bool $degraded=false;
    private bool $healthy=true;
    public function __construct(private readonly AuditSink $audit,private readonly ?MetricSink $metrics=null,
        private readonly bool $metricsRequired=false) {}
    /** @param list<Metric> $metrics */
    public function emit(Event $event,array $metrics=[]): Ref
    {
        if (!$this->healthy) { throw new \AgenToolsPlatform\Core\Observability\Failure('reset_failed'); }
        try { $receipt=$this->audit->append($event); $event->id->tenant->assertSame($receipt->tenant); }
        catch (\Throwable) { throw new \AgenToolsPlatform\Core\Observability\Failure('audit_unavailable'); }
        foreach ($metrics as $metric) {
            try {
                if ($this->metrics===null) { throw new \AgenToolsPlatform\Core\Observability\Failure('metrics_unavailable'); }
                $this->metrics->observe($metric);
            } catch (\Throwable) {
                $this->degraded=true;
                if ($this->metricsRequired) { throw new \AgenToolsPlatform\Core\Observability\Failure('metrics_unavailable'); }
            }
        }
        return $receipt;
    }
    /** @return array{healthy:bool,metrics_degraded:bool} */
    public function health(): array { return ['healthy'=>$this->healthy,'metrics_degraded'=>$this->degraded]; }
    public function reset(): void
    {
        $failed=false;
        try { $this->audit->reset(); } catch (\Throwable) { $failed=true; }
        try { $this->metrics?->reset(); } catch (\Throwable) { $failed=true; }
        if ($failed) { $this->healthy=false; throw new \AgenToolsPlatform\Core\Observability\Failure('reset_failed'); }
    }
    /** @return array{healthy:bool,metrics_degraded:bool} */
    public function __debugInfo(): array { return $this->health(); }
}
