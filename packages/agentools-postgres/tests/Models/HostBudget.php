<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Tests\Models;

use AgenToolsPlatform\Core\Budget\{Admission, AdmissionRequest, BudgetPort};
use AgenToolsPlatform\Core\Common\{IntegrityConflict, Ref, UtcInstant};

final class HostBudget implements BudgetPort
{
    public string $outcome = 'admitted';
    public int $releases = 0;
    public function __construct(private TestRig $rig) {}
    public function status(AdmissionRequest $request): Admission
    {
        $this->rig->boundary('budget-status');
        if ($this->outcome !== 'admitted') { return new Admission($this->outcome, reason: 'owner_outage_or_denial'); }
        $row = $this->rig->sql('SELECT hash FROM host_admissions WHERE tenant=:tenant AND key=:key',
            ['tenant' => $request->operationKey->tenant->value, 'key' => $request->operationKey->value])->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) { return new Admission('not_found'); }
        if ($row['hash'] !== $request->hash->value) { throw new IntegrityConflict('ADMISSION_PAYLOAD_CONFLICT'); }
        return $this->accepted($request);
    }
    public function admit(AdmissionRequest $request): Admission
    {
        $this->rig->boundary('budget');
        if ($this->outcome !== 'admitted') { return new Admission($this->outcome); }
        $this->rig->sql('INSERT INTO host_admissions(tenant,key,hash) VALUES (:tenant,:key,:hash) ON CONFLICT DO NOTHING', [
            'tenant' => $request->operationKey->tenant->value, 'key' => $request->operationKey->value, 'hash' => $request->hash->value]);
        $this->rig->boundary('admission');
        return $this->status($request);
    }
    private function accepted(AdmissionRequest $request): Admission
    {
        return new Admission('admitted', new Ref($request->operationKey->tenant, 'grant:' . $request->operationKey->value),
            new Ref($request->operationKey->tenant, 'reserve:' . $request->operationKey->value), new UtcInstant('2030-01-02T00:00:00Z'), 'owner-1');
    }
    public function settle(Ref $reservation, Ref $usageEvent): void { $this->rig->boundary('settle'); }
    public function release(Ref $reservation, string $provenNotSentReason): void { ++$this->releases; }
    public function renew(Ref $reservation, UtcInstant $deadline): Admission { throw new \LogicException('No automatic renew in this slice'); }
}
