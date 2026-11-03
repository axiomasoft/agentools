<?php
declare(strict_types=1);
namespace AgenToolsPlatform\ModelAdapter\Tests\Cache;
use AgenToolsPlatform\Core\Common\{Ref,UtcInstant};
use AgenToolsPlatform\Core\Models\{CapabilityEvidence,Evidence,ModelUsage,Support};
use AgenToolsPlatform\Core\Models\Cache\{Capabilities,Descriptor,Key,Provider as Port,ProviderResult};

final class Provider implements Port
{
    /** @var list<string> */ public array $enabled=['create','use','refresh','delete','status'];
    public string $useStatus='hit'; public bool $lostCreate=false; public bool $lostDelete=false;
    public int $creates=0; public int $uses=0; public int $deletes=0; public int $refreshes=0;
    public ?\Closure $afterCreate=null; public ?\Closure $onRefresh=null; public ?\Closure $onDelete=null; public ?\Closure $afterDelete=null; public ?\Closure $onStatus=null;
    /** @var list<string> */ public array $lookups=[];
    public function __construct(private Fixture $f) {}
    public function capabilities(Key $key): Capabilities
    {
        $ops=[]; foreach ($this->enabled as $op) { $ops[$op]=new CapabilityEvidence(Support::Supported,Evidence::ProbeVerified,
            new UtcInstant('2020-01-01T00:00:00Z'),new UtcInstant('2031-01-01T00:00:00Z'),'scripted-contract-only'); }
        return new Capabilities($ops);
    }
    public function create(Key $key,Ref $operation,UtcInstant $expiry): ProviderResult
    {
        $this->f->store->assertIdle(); ++$this->creates;
        if ($this->afterCreate!==null) { ($this->afterCreate)(); }
        if ($this->lostCreate) { throw new \RuntimeException('secret raw cache payload'); }
        return new ProviderResult('created',$this->f->ref('provider-resource'),$expiry,usage:new ModelUsage(100,12,40,null),amount:'0.02',currency:'USD');
    }
    public function use(Descriptor $descriptor): ProviderResult { $this->f->store->assertIdle(); ++$this->uses; return new ProviderResult($this->useStatus); }
    public function refresh(Descriptor $descriptor,Ref $operation,UtcInstant $expiry): ProviderResult
    { ++$this->refreshes; if ($this->onRefresh!==null) { ($this->onRefresh)(); } return new ProviderResult('created',$descriptor->artifact,$expiry,usage:new ModelUsage(0,0,0,null)); }
    public function delete(Descriptor $descriptor,Ref $operation): ProviderResult
    {
        $this->f->store->assertIdle(); ++$this->deletes;
        if ($this->onDelete!==null) { ($this->onDelete)(); }
        if ($this->afterDelete!==null) { ($this->afterDelete)(); }
        if ($this->lostDelete) { throw new \RuntimeException('secret provider delete error'); }
        return new ProviderResult('deleted',receipt:$this->f->ref('delete-ack'));
    }
    public function status(Key $key,Ref $operation): ProviderResult
    { $this->f->store->assertIdle(); $this->lookups[]=$operation->value; return $this->onStatus!==null?($this->onStatus)($operation):new ProviderResult('deleted',receipt:$this->f->ref('delete-ack')); }
}
