<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Testing\Providers;
use AgenToolsPlatform\Core\Common\UtcInstant;
use AgenToolsPlatform\Core\Models\{Deployment,ModelEvent,ModelRequest,ModelResponse,ModelTransport,TransportPreflight};

/** Development-only provider fixture. Scripted support is never live/provider qualification. */
final class ScriptedTransport implements ModelTransport
{
    public int $calls=0;
    /**
     * @param \Closure(ModelRequest,Deployment): ModelResponse $response
     * @param \Closure(ModelRequest,Deployment): iterable<ModelEvent> $events
     * @param \Closure(): UtcInstant $clock
     */
    public function __construct(private readonly \Closure $response,private readonly \Closure $events,private readonly \Closure $clock) {}
    public function generate(ModelRequest $request,Deployment $deployment): ModelResponse
    { TransportPreflight::check($request,$deployment,($this->clock)()); ++$this->calls; return ($this->response)($request,$deployment); }
    public function stream(ModelRequest $request,Deployment $deployment): iterable
    { TransportPreflight::check($request,$deployment,($this->clock)(),true); ++$this->calls; yield from ($this->events)($request,$deployment); }
}
