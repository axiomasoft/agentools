<?php
declare(strict_types=1);
namespace AgenToolsPlatform\ModelAdapter\Tests\Cache;
use AgenToolsPlatform\Core\Common\UtcInstant;
use AgenToolsPlatform\Core\Models\Cache\{Admission,Guard,Key,Prepared,Rebuilder,Rights};

final class Host implements Guard,Rebuilder,Admission
{
    public bool $revoked=false; public bool $storage=true; public bool $warm=true; public bool $stable=true;
    public bool $budget=true; public bool $overflow=false; public bool $forbiddenRoute=false;
    public int $checks=0; public int $rebuilds=0; public int $admissions=0; public ?\Closure $onAdmit=null;
    public function __construct(private Fixture $f) {}
    public function current(Key $key,UtcInstant $now): Rights
    {
        $this->f->store->assertIdle(); ++$this->checks; if ($this->revoked) { throw new \LogicException('Current material ACL/revoke denied'); }
        return new Rights(new \AgenToolsPlatform\Core\Common\Ref($key->id->tenant,'rights-current'),new UtcInstant('2031-01-01T00:00:00Z'),$this->storage,$this->warm,$this->stable);
    }
    public function rebuild(Key $requested,UtcInstant $now): Prepared
    {
        $this->f->store->assertIdle(); ++$this->rebuilds; $this->current($requested,$now);
        if ($this->overflow) { return new Prepared($requested,$this->f->ref('too-large'),$this->f->prepared($requested)->contextHash,1000,100,10,10,1); }
        return $this->f->prepared($this->forbiddenRoute?$this->f->key(['provider'=>'forbidden-cheap']):$requested);
    }
    public function admit(Prepared $prepared,UtcInstant $now): void
    { $this->f->store->assertIdle(); ++$this->admissions; if (!$this->budget) { throw new \LogicException('Owner admission denied'); } if ($this->onAdmit!==null) { ($this->onAdmit)(); } }
}
