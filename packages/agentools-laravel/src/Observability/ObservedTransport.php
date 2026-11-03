<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Laravel\Observability;

use AgenToolsPlatform\Core\Models\{ModelTransport,ModelRequest,ModelResponse,Deployment};
use AgenToolsPlatform\Core\Observability\Outcome;
use AgenToolsPlatform\Laravel\Managed\{HostBindings,ProductionBinding};

/** Decorates the existing call exactly once. It never retries, merges responses or logs provider bytes. */
final readonly class ObservedTransport implements ModelTransport,ProductionBinding
{
    public function __construct(private ModelTransport $inner,private Telemetry $telemetry) {}
    public function assertProduction(): void { HostBindings::assertProductionService($this->inner);$this->telemetry->assertProduction(); }
    public function generate(ModelRequest $request,Deployment $deployment): ModelResponse
    {
        $this->telemetry->provider($request,$deployment,Outcome::Waiting);$start=hrtime(true);
        try { $result=$this->inner->generate($request,$deployment); }
        catch (\Throwable $error) {
            $this->telemetry->provider($request,$deployment,Outcome::Unknown,(hrtime(true)-$start)/1_000_000,$error);throw $error;
        }
        $this->telemetry->provider($request,$deployment,Outcome::Succeeded,(hrtime(true)-$start)/1_000_000);
        return $result;
    }
    public function stream(ModelRequest $request,Deployment $deployment): iterable
    {
        $this->telemetry->provider($request,$deployment,Outcome::Waiting);$start=hrtime(true);$outcome=Outcome::Interrupted;
        try { foreach ($this->inner->stream($request,$deployment) as $chunk) { yield $chunk; } $outcome=Outcome::Succeeded; }
        catch (\Throwable $error) { $outcome=Outcome::Unknown;throw $error; }
        finally { $this->telemetry->provider($request,$deployment,$outcome,(hrtime(true)-$start)/1_000_000); }
    }
}
