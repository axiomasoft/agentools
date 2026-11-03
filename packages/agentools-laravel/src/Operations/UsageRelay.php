<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Laravel\Operations;

use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Usage\UsageEvent;
use AgenToolsPlatform\Postgres\Usage\{Dispatcher,ExportStatus,MeteringPort,Repository};

/** Reuses the single accounting dispatcher with an explicit key; never scans some other export. */
final readonly class UsageRelay implements \AgenToolsPlatform\Laravel\Managed\ProductionBinding
{
    public function __construct(private Repository $repository,private MeteringPort $owner) {}
    public function assertProduction(): void
    {
        \AgenToolsPlatform\Laravel\Managed\HostBindings::assertProductionService($this->owner);
    }
    /** @param \Closure():void $reauthorize */
    public function dispatch(Command $c,\Closure $reauthorize): string
    {
        $c->id->tenant->assertSame($this->repository->tenant);
        $owner=new class($this->owner,$reauthorize) implements MeteringPort {
            /** @param \Closure():void $check */
            public function __construct(private MeteringPort $owner,private \Closure $check) {}
            public function status(UsageEvent $event): ExportStatus { ($this->check)(); return $this->owner->status($event); }
            public function export(UsageEvent $event): ExportStatus { ($this->check)(); return $this->owner->export($event); }
        };
        $reauthorize();
        $ref=new Ref($c->id->tenant,$c->target);
        (new Dispatcher($this->repository,$owner))->dispatchOne($ref);
        $reauthorize();
        $state=$this->repository->exportState($ref);
        return $state['status']==='exported'?'exported':'unknown';
    }
}
