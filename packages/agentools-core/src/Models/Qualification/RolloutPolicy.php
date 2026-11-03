<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Core\Models\Qualification;
use AgenToolsPlatform\Core\Common\{CanonicalHasher, IntegrityConflict, PayloadHash, Ref, TenantId, UtcInstant};
use AgenToolsPlatform\Core\Models\Time;

/** Immutable owner-authored cohort policy; thresholds have an explicit saved baseline. */
final readonly class RolloutPolicy
{
    /** @param array<string,array{min?:int|float,max?:int|float}> $bounds */
    public function __construct(public Ref $ref, public Ref $stable, public ?Ref $canary,
        public int $cohortBasisPoints, public int $maxAdmissions, public Ref $baseline,
        public PayloadHash $baselineHash, public int $minimumSamples, public array $bounds,
        public Ref $reason, public ?Ref $rollbackOf = null)
    {
        foreach ([$stable,$canary,$baseline,$reason,$rollbackOf] as $r) { if ($r !== null) { $ref->tenant->assertSame($r->tenant); } }
        if ($cohortBasisPoints < 0 || $cohortBasisPoints > 10000 || $maxAdmissions < 0 || $maxAdmissions > 9007199254740991
            || $minimumSamples < 1 || ($canary === null && $cohortBasisPoints !== 0)
            || ($cohortBasisPoints > 0 && ($maxAdmissions < 1 || $bounds === []))
            || ($rollbackOf !== null && $canary !== null)) { throw new \InvalidArgumentException('Bounded owner rollout policy required'); }
        foreach ($bounds as $metric => $bound) {
            if ($metric === '' || $bound === [] || array_diff(array_keys($bound),['min','max']) !== []) { throw new \InvalidArgumentException('Explicit owner metric bounds required'); }
            foreach ($bound as $value) { Data::finite($value); }
            if (isset($bound['min'],$bound['max']) && $bound['min'] > $bound['max']) { throw new \InvalidArgumentException('Contradictory owner bounds'); }
        }
    }
    public function inCohort(Ref $session, CanonicalHasher $hasher): bool
    {
        $this->ref->tenant->assertSame($session->tenant);
        $digest = $hasher->hash($session->tenant,'qualification-cohort',['session'=>$session->value,'policy'=>$this->ref->value])->value;
        $bytes = hex2bin(substr($digest,8,8));
        if ($bytes === false) { throw new \LogicException('Invalid canonical digest'); }
        $bucket = 0;
        for ($i = 0; $i < 4; ++$i) { $bucket = $bucket * 256 + ord($bytes[$i]); }
        return $bucket % 10000 < $this->cohortBasisPoints;
    }
    public function measured(?Measurements $m, Snapshot $candidate, UtcInstant $now, CanonicalHasher $hasher): bool
    {
        if ($m === null || $m->evidence->tenant->value !== $this->ref->tenant->value || $m->baseline->value !== $this->baseline->value
            || $m->baselineHash->value !== $this->baselineHash->value || $m->tupleHash->value !== $candidate->tuple->hash($hasher)->value
            || $m->samples < $this->minimumSamples || $m->hardViolations !== 0 || Time::compare($m->measuredAt,$now) > 0
            || Time::compare($now,$m->expiresAt) >= 0) { return false; }
        foreach ($this->bounds as $metric => $bound) {
            $value = $m->values[$metric] ?? null;
            if ($value === null || (isset($bound['min']) && $value < $bound['min']) || (isset($bound['max']) && $value > $bound['max'])) { return false; }
        }
        return true;
    }
    /** Selection intent only. The store must serialize capacity and enforce current guards before persisting. */
    public function choose(Ref $session, Snapshot $stable, ?Snapshot $canary, ?Measurements $measurements,
        int $admitted, UtcInstant $now, CanonicalHasher $hasher): Assignment
    {
        $this->stable->assertSame($stable->ref);
        if ($canary !== null) { $this->canary?->assertSame($canary->ref); }
        if (($this->canary === null) !== ($canary === null) || $admitted < 0) { throw new \AgenToolsPlatform\Core\Common\IntegrityConflict('Rollout candidate binding mismatch'); }
        $reason = 'outside_cohort'; $selected = $stable; $isCanary = false;
        if ($canary !== null && $this->inCohort($session,$hasher)) {
            if (!$this->measured($measurements,$canary,$now,$hasher)) { $reason = 'owner_measurement_absent_or_outside_bounds'; }
            elseif ($admitted >= $this->maxAdmissions) { $reason = 'canary_admission_bound'; }
            else { $selected = $canary; $isCanary = true; $reason = 'owner_measured_canary'; }
        }
        return new \AgenToolsPlatform\Core\Models\Qualification\Assignment($session,$this->ref,$selected->ref,$selected->tuple->hash($hasher),$isCanary,$reason);
    }
    /** @return array<string,mixed> */
    public function toArray(): array
    { return ['id'=>$this->ref->value,'stable'=>$this->stable->value,'canary'=>$this->canary?->value,'cohort_basis_points'=>$this->cohortBasisPoints,
        'max_admissions'=>$this->maxAdmissions,'baseline'=>$this->baseline->value,'baseline_hash'=>$this->baselineHash->value,
        'minimum_samples'=>$this->minimumSamples,'bounds'=>$this->bounds,'reason'=>$this->reason->value,'rollback_of'=>$this->rollbackOf?->value]; }
    /** @param array<string,mixed> $data */
    public static function fromArray(TenantId $tenant, array $data): self
    {
        $bounds = [];
        foreach (Data::object($data['bounds'] ?? null) as $name => $value) {
            $bound = Data::object($value); $typed = [];
            foreach ($bound as $key => $number) {
                if (!in_array($key,['min','max'],true) || (!is_int($number) && !is_float($number))) { throw new \InvalidArgumentException('Numeric owner bound required'); }
                $typed[$key] = $number;
            }
            /** @var array{min?:int|float,max?:int|float} $typed */
            $bounds[$name] = $typed;
        }
        return new \AgenToolsPlatform\Core\Models\Qualification\RolloutPolicy(new \AgenToolsPlatform\Core\Common\Ref($tenant,Data::text($data,'id')),new \AgenToolsPlatform\Core\Common\Ref($tenant,Data::text($data,'stable')),
            ($data['canary'] ?? null) === null ? null : new \AgenToolsPlatform\Core\Common\Ref($tenant,Data::text($data,'canary')),
            Data::integer($data,'cohort_basis_points'),Data::integer($data,'max_admissions'),new \AgenToolsPlatform\Core\Common\Ref($tenant,Data::text($data,'baseline')),
            new \AgenToolsPlatform\Core\Common\PayloadHash(Data::text($data,'baseline_hash')),Data::integer($data,'minimum_samples'),$bounds,new \AgenToolsPlatform\Core\Common\Ref($tenant,Data::text($data,'reason')),
            ($data['rollback_of'] ?? null) === null ? null : new \AgenToolsPlatform\Core\Common\Ref($tenant,Data::text($data,'rollback_of')));
    }
}
