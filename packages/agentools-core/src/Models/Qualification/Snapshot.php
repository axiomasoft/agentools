<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models\Qualification;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, IntegrityConflict, Ref, UtcInstant};
use AgenToolsPlatform\Core\Models\Time;

/** Immutable snapshot derives its decision from evidence; health is never an input. */
final readonly class Snapshot
{
    public string $decision;
    public function __construct(public Ref $ref, public Tuple $tuple, public EvidencePolicy $policy,
        public Evidence $evidence, public UtcInstant $issuedAt, public ?UtcInstant $expiresAt, CanonicalHasher $hasher)
    {
        $ref->tenant->assertSame($tuple->tenant); $ref->tenant->assertSame($policy->ref->tenant); $ref->tenant->assertSame($evidence->ref->tenant);
        if ($tuple->components['evidence_policy_hash'] !== $policy->hash($hasher)->value || $tuple->hash($hasher)->value !== $evidence->tupleHash->value
            || ($expiresAt !== null && Time::compare($issuedAt,$expiresAt) >= 0)) { throw new \AgenToolsPlatform\Core\Common\IntegrityConflict('Qualification evidence/tuple/policy binding mismatch'); }
        $this->decision = $evidence->decision($policy);
    }
    public function permits(Tuple $candidate, EvidencePolicy $policy, UtcInstant $now, CanonicalHasher $hasher): bool
    {
        return $candidate->tenant->value === $this->ref->tenant->value && $policy->ref->tenant->value === $this->ref->tenant->value
            && $this->decision === 'qualified' && $this->tuple->hash($hasher)->value === $candidate->hash($hasher)->value
            && $this->policy->hash($hasher)->value === $policy->hash($hasher)->value && Time::compare($this->issuedAt,$now) <= 0
            && ($this->expiresAt === null || Time::compare($now,$this->expiresAt) < 0);
    }
    /** @return array<string,mixed> */
    public function toArray(): array
    { return ['id'=>$this->ref->value,'tuple'=>$this->tuple->toArray(),'policy'=>$this->policy->toArray(),'evidence'=>$this->evidence->toArray(),
        'decision'=>$this->decision,'issued_at'=>$this->issuedAt->value,'expires_at'=>$this->expiresAt?->value]; }
    /** @param array<string,mixed> $data */
    public static function fromArray(array $data, CanonicalHasher $hasher): self
    {
        $tuple = Tuple::fromArray(Data::object($data['tuple'] ?? null)); $tenant = $tuple->tenant;
        $snapshot = new \AgenToolsPlatform\Core\Models\Qualification\Snapshot(new \AgenToolsPlatform\Core\Common\Ref($tenant,Data::text($data,'id')),$tuple,EvidencePolicy::fromArray($tenant,Data::object($data['policy'] ?? null)),
            Evidence::fromArray($tenant,Data::object($data['evidence'] ?? null)),new \AgenToolsPlatform\Core\Common\UtcInstant(Data::text($data,'issued_at')),
            ($data['expires_at'] ?? null) === null ? null : new \AgenToolsPlatform\Core\Common\UtcInstant(Data::text($data,'expires_at')),$hasher);
        if ($snapshot->decision !== Data::text($data,'decision')) { throw new \AgenToolsPlatform\Core\Common\IntegrityConflict('Qualification decision tampered'); }
        return $snapshot;
    }
}
