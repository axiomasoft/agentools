<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Tests\Qualification;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, PayloadHash, Ref, TenantId, UtcInstant};
use AgenToolsPlatform\Core\Models\Qualification\{Compatibility, Evidence, EvidencePolicy, Measurements, RequirementSource, Requirements, RolloutPolicy, SelectionGate, Snapshot, SnapshotCatalog, SnapshotStore, Tuple};
use AgenToolsPlatform\Core\Models\Routing\{CatalogEntry, RouteRequest, Router};
use AgenToolsPlatform\Core\Models\Time;
use AgenToolsPlatform\Tests\Routing\Fixture as F;

final class Fixture implements SnapshotStore, RequirementSource, SelectionGate, Compatibility
{
    public F $f;
    public Requirements $requirements;
    /** @var list<Snapshot> */
    public array $snapshots = [];
    public bool $revoked = false;
    public bool $compatible = true;
    public function __construct()
    {
        $this->f = new F(); $this->requirements = new Requirements(F::ref('generic-teacher'),F::digest('b'),F::digest('c'),$this->policy());
        $this->f->states = [$this->f->qualified($this->f->entry())];
    }
    public function policy(string $scope = 'technical-contract-only'): EvidencePolicy
    { return new EvidencePolicy(F::ref('evidence-policy'),['A','B'],['technical.hard'],['contract-scripted','isolated-postgres','local-host-e2e','live-provider','product-pilot'],$scope); }
    public function tuple(?CatalogEntry $entry = null, ?RouteRequest $request = null, ?EvidencePolicy $policy = null): Tuple
    {
        $r = $policy === null ? $this->requirements : new Requirements($this->requirements->profile,$this->requirements->bundleHash,$this->requirements->policyHash,$policy);
        return Tuple::forRoute($request ?? $this->f->request(),$entry ?? $this->f->entry(),$r,$this->f->hasher);
    }
    /**
     * @param list<string> $executed
     * @param array<string,string> $scorers
     */
    public function snapshot(string $id = 'old', ?CatalogEntry $entry = null, ?EvidencePolicy $policy = null,
        array $executed = ['A','B'], array $scorers = ['technical.hard'=>'pass','owner.domain'=>'insufficient_evidence'],
        string $kind = 'contract-scripted', string $domain = 'insufficient_evidence', string $live = 'not_run',
        string $issued = '2026-11-01T00:00:00Z', ?string $expires = '2026-12-01T00:00:00Z'): Snapshot
    {
        $policy ??= $this->requirements->evidencePolicy; $tuple = $this->tuple($entry,null,$policy);
        $evidence = new Evidence(F::ref('result-'.$id),F::digest('d'),$tuple->hash($this->f->hasher),$kind,['A','B'],$executed,$scorers,$domain,$live);
        return new Snapshot(F::ref($id),$tuple,$policy,$evidence,new UtcInstant($issued),$expires === null ? null : new UtcInstant($expires),$this->f->hasher);
    }
    public function latest(TenantId $tenant, PayloadHash $tupleHash, UtcInstant $now): ?Snapshot
    {
        $best = null;
        foreach ($this->snapshots as $s) {
            if ($s->ref->tenant->value !== $tenant->value || $s->tuple->hash($this->f->hasher)->value !== $tupleHash->value || Time::compare($s->issuedAt,$now) > 0) { continue; }
            if ($best === null || Time::compare($s->issuedAt,$best->issuedAt) > 0 || (Time::compare($s->issuedAt,$best->issuedAt) === 0 && $s->decision !== 'qualified')) { $best = $s; }
        }
        return $best;
    }
    public function current(RouteRequest $request, CatalogEntry $entry): ?Requirements { return $this->revoked ? null : $this->requirements; }
    public function router(?RouteRequest $request = null): Router
    {
        $r = $request ?? $this->f->request();
        return new Router(new SnapshotCatalog($this->f,$this,$this,$r,$this->f->hasher,$this->f),$this->f,$this->f->hasher);
    }
    public function authorize(Snapshot $snapshot, UtcInstant $now): void
    {
        foreach ($this->f->states as $state) {
            if ($state->entry->deployment->ref->value === $snapshot->tuple->components['deployment_ref']) {
                $tuple = $this->tuple($state->entry);
                if (!$snapshot->permits($tuple,$this->requirements->evidencePolicy,$now,$this->f->hasher)
                    || $this->router()->revalidate($this->f->request(),$state->entry,$now,true) !== []) { break; }
                return;
            }
        }
        throw new ContractViolation(ErrorCode::AccessDenied);
    }
    public function assertCompatible(Snapshot $previous, Snapshot $target, Ref $reason): void
    {
        $previous->ref->tenant->assertSame($target->ref->tenant); $target->ref->tenant->assertSame($reason->tenant);
        if (!$this->compatible || $this->revoked || $previous->tuple->components['task_ref'] !== $target->tuple->components['task_ref']
            || $previous->tuple->components['profile_ref'] !== $target->tuple->components['profile_ref']) { throw new ContractViolation(ErrorCode::AccessDenied); }
    }
    public function rollout(Ref $stable, ?Ref $canary, string $id = 'rollout', int $limit = 1, ?Ref $rollbackOf = null): RolloutPolicy
    { return new RolloutPolicy(F::ref($id),$stable,$canary,$canary === null ? 0 : 10000,$limit,F::ref('owner-baseline'),F::digest('e'),1,['latency_ms'=>['max'=>200]],F::ref('owner-reason'),$rollbackOf); }
    public function measurements(Snapshot $candidate): Measurements
    { return new Measurements(F::ref('owner-measurements'),F::ref('owner-baseline'),F::digest('e'),$candidate->tuple->hash($this->f->hasher),5,['latency_ms'=>100],0,new UtcInstant('2026-11-01T01:00:00Z'),new UtcInstant('2026-12-01T00:00:00Z')); }
}
