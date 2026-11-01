<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Security;

use AgenToolsPlatform\Core\Common\{Ref,SessionId};

/** Worker health only is retained; tenant/actor/credential state belongs to resettable host resources. */
final class WorkerScope
{
    private bool $healthy=true;
    /** @param list<ResettableResource> $resources */
    public function __construct(private readonly HostAuthorization $host,private readonly array $resources)
    {
        $kinds=[];foreach ($resources as $resource) { $key=$resource->kind()->value;if (isset($kinds[$key])) { throw new \InvalidArgumentException('One reset owner per resource kind required'); }$kinds[$key]=true; }
        foreach (ResourceKind::cases() as $kind) { if (!isset($kinds[$kind->value])) { throw new \InvalidArgumentException('All per-job resources must have reset owners'); } }
    }
    public function execute(SessionId $session,string $purpose,Correlation $trace,WorkerJob $job): Ref
    {
        if (!$this->healthy) { throw new \RuntimeException('Worker is quarantined after reset failure'); }
        $this->reset();
        try { $context=ExecutionContext::fromHost($this->host,$session,$purpose,$trace);$result=$job->run($context);$session->tenant->assertSame($result->tenant);return $result; }
        finally { $this->reset(); }
    }
    /** @return array<string,mixed> */
    public function __debugInfo(): array { return ['healthy'=>$this->healthy,'resource_count'=>count($this->resources)]; }
    private function reset(): void
    {
        $failed=false;foreach ($this->resources as $resource) { try { $resource->reset(); }catch (\Throwable) { $failed=true; } }
        if ($failed) { $this->healthy=false;throw new \RuntimeException('Worker resource reset failed; worker quarantined'); }
    }
}
