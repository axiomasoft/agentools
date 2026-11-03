<?php
declare(strict_types=1);
namespace AgenToolsPlatform\ModelAdapter\Tests\Cache;
use AgenToolsPlatform\Core\Budget\{Admission,AdmissionConflict,AdmissionRequest,BudgetGuard,BudgetPort};
use AgenToolsPlatform\Core\Common\{Ref,UtcInstant};
final class BudgetOwner implements BudgetPort,BudgetGuard
{
    /** @var array<string,string> */ private array $requests=[];
    public string $outcome='admitted'; public bool $revoked=false;
    public ?\Closure $afterAdmit=null;
    public int $admissions=0; public int $checks=0; public int $releases=0;
    public function __construct(private Fixture $f) {}
    public function assertCurrent(AdmissionRequest $request,UtcInstant $now): void
    { $this->f->store->assertIdle(); ++$this->checks; if ($this->revoked) { throw new \LogicException('Current budget guard denied'); } }
    public function status(AdmissionRequest $request): Admission
    {
        $this->f->store->assertIdle(); $id=$request->operationKey->value;
        if (isset($this->requests[$id]) && $this->requests[$id]!==$request->hash->value) { throw new AdmissionConflict(); }
        if ($this->outcome!=='admitted') { return new Admission($this->outcome,reason:'SCRIPTED_OWNER'); }
        return isset($this->requests[$id])?$this->allowed():new Admission('not_found');
    }
    public function admit(AdmissionRequest $request): Admission
    { $this->f->store->assertIdle(); ++$this->admissions; $this->requests[$request->operationKey->value]=$request->hash->value; if ($this->afterAdmit!==null) { ($this->afterAdmit)(); } return $this->allowed(); }
    public function settle(Ref $reservation,Ref $usageEvent): void { $this->f->store->assertIdle(); }
    public function release(Ref $reservation,string $provenNotSentReason): void { ++$this->releases; }
    public function renew(Ref $reservation,UtcInstant $deadline): Admission { throw new \LogicException('No automatic warm renewal'); }
    private function allowed(): Admission { return new Admission('admitted',$this->f->ref('warm-admission'),$this->f->ref('warm-reservation'),new UtcInstant('2030-01-02T00:00:00Z'),'scripted-owner-v1'); }
}
