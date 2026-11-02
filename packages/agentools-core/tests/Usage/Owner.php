<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Tests\Usage;

use AgenToolsPlatform\Core\Budget\{Admission, AdmissionConflict, AdmissionRequest, BudgetGuard, BudgetPort};
use AgenToolsPlatform\Core\Common\{Ref, UtcInstant};

/** Test-only external-owner stand-in; production has no unlimited/default owner. */
final class Owner implements BudgetPort, BudgetGuard
{
    /** @var array<string,string> */
    private array $bindings = [];
    public int $admissions = 0;
    public int $settlements = 0;
    public int $releases = 0;
    public int $renewals = 0;
    public int $guards = 0;
    public bool $denied = false;
    public bool $lost = false;
    public string $status = 'not_found';
    public function assertCurrent(AdmissionRequest $request,UtcInstant $now): void
    { ++$this->guards; if ($this->denied) { throw new \LogicException('Current entitlement/emergency denied'); } }
    public function status(AdmissionRequest $request): Admission
    {
        $id = $request->operationKey->value;
        if (isset($this->bindings[$id]) && $this->bindings[$id] !== $request->hash->value) { throw new AdmissionConflict(); }
        $this->bindings[$id] = $request->hash->value;
        if ($this->lost) { throw new \RuntimeException('owner reply lost'); }
        return $this->status === 'admitted' ? $this->allowed() : new Admission($this->status,reason:'SCRIPTED_OWNER');
    }
    public function admit(AdmissionRequest $request): Admission { ++$this->admissions; $this->status = 'admitted'; return $this->allowed(); }
    public function settle(Ref $reservation, Ref $usageEvent): void { ++$this->settlements; if ($this->lost) { throw new \RuntimeException('settle reply lost'); } }
    public function release(Ref $reservation,string $provenNotSentReason): void { ++$this->releases; if ($this->lost) { throw new \RuntimeException('release reply lost'); } }
    public function renew(Ref $reservation,UtcInstant $deadline): Admission { ++$this->renewals; return $this->allowed(); }
    private function allowed(): Admission { return new Admission('admitted',Fixture::ref('admission-1'),Fixture::ref('reservation-1'),new UtcInstant('2026-11-02T11:00:00Z'),'owner-v1'); }
}
