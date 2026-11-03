<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Postgres\Tests\Qualification;
use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, Ref, TenantId, UtcInstant};
use AgenToolsPlatform\Core\Models\Qualification\{Compatibility, SelectionGate, Snapshot, SnapshotCatalog};
use AgenToolsPlatform\Core\Models\Routing\Router;
use AgenToolsPlatform\Postgres\Qualification\{Authority, Repository};
use AgenToolsPlatform\Postgres\Runtime\Connection;
use AgenToolsPlatform\Tests\Qualification\Fixture as Q;
use AgenToolsPlatform\Tests\Routing\Fixture as F;

final class Fixture implements Authority, SelectionGate, Compatibility
{
    public Q $q;
    public Repository $repo;
    public bool $denied = false;
    public function __construct(public Connection $db)
    { $this->q = new Q(); $this->repo = new Repository($db,$this->q->f->hasher,$this,F::ref('admin')); }
    /** @return array{Snapshot,Snapshot} */
    public function seed(): array
    {
        $a = $this->q->snapshot(); $entry = $this->q->f->entry(['id'=>'canary']); $b = $this->q->snapshot('new',$entry);
        $this->q->f->states = [$this->q->f->qualified($this->q->f->entry()),$this->q->f->qualified($entry)];
        $this->q->snapshots = [$a,$b]; $this->repo->put($a); $this->repo->put($b); return [$a,$b];
    }
    public function authorize(TenantId|Snapshot $subject, Ref|UtcInstant $actor, ?string $operation = null): void
    {
        if ($subject instanceof TenantId) {
            if ($this->denied || !$actor instanceof Ref || $actor->tenant->value !== $subject->value) { throw new ContractViolation(ErrorCode::AccessDenied); }
            return;
        }
        if (!$actor instanceof UtcInstant) { throw new \LogicException('Qualification time required'); }
        foreach ($this->q->f->states as $state) {
            if ($state->entry->deployment->ref->value !== $subject->tuple->components['deployment_ref']) { continue; }
            $r = $this->q->f->request(); $tuple = $this->q->tuple($state->entry);
            $catalog = new SnapshotCatalog($this->q->f,$this->repo,$this->q,$r,$this->q->f->hasher,$this->q->f);
            if (!$this->q->revoked && $subject->permits($tuple,$this->q->requirements->evidencePolicy,$actor,$this->q->f->hasher)
                && (new Router($catalog,$this->q->f,$this->q->f->hasher))->revalidate($r,$state->entry,$actor,true) === []) { return; }
        }
        throw new ContractViolation(ErrorCode::AccessDenied);
    }
    public function assertCompatible(Snapshot $previous, Snapshot $target, Ref $reason): void
    { $this->q->assertCompatible($previous,$target,$reason); }
}
