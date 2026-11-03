<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Postgres\Qualification;
use AgenToolsPlatform\Core\Common\{CanonicalHasher, ContractViolation, ErrorCode, IntegrityConflict, PayloadHash, Ref, TenantId, UtcInstant};
use AgenToolsPlatform\Core\Models\Qualification\{Assignment, Compatibility, Data, Measurements, RolloutPolicy, SelectionGate, Snapshot, SnapshotStore};
use AgenToolsPlatform\Core\Models\Time;
use AgenToolsPlatform\Postgres\Runtime\Connection;

/** Tenant scoped append-only snapshots, audited publication and serial bounded first assignment. */
final readonly class Repository implements SnapshotStore
{
    public function __construct(private Connection $db, private CanonicalHasher $hasher, private Authority $authority, private Ref $actor) {}

    public function put(Snapshot $snapshot): void
    {
        $this->authorize($snapshot->ref->tenant,'qualification.snapshot.put');
        $this->db->transaction(function () use ($snapshot): void {
            $this->insert('qualification_snapshots',$snapshot->ref,$snapshot->toArray(),
                ['tuple_hash'=>$snapshot->tuple->hash($this->hasher)->value,'issued_at'=>$snapshot->issuedAt->value,'decision'=>$snapshot->decision]);
        });
    }
    public function snapshot(Ref $id): Snapshot
    {
        $this->authorize($id->tenant,'qualification.read');
        $data = $this->load('qualification_snapshots',$id);
        if ($data === null) { throw new IntegrityConflict('Qualification snapshot missing'); }
        $snapshot = Snapshot::fromArray($data,$this->hasher); $id->assertSame($snapshot->ref);
        return $snapshot;
    }
    public function latest(TenantId $tenant, PayloadHash $tupleHash, UtcInstant $now): ?Snapshot
    {
        $this->authorize($tenant,'qualification.read'); $latest = null;
        $rows = $this->db->execute('SELECT id FROM '.$this->db->table('qualification_snapshots').'
            WHERE tenant_id=:tenant AND tuple_hash=:hash ORDER BY id',['tenant'=>$tenant->value,'hash'=>$tupleHash->value]);
        foreach ($rows->fetchAll(\PDO::FETCH_ASSOC) as $raw) {
            $snapshot = $this->snapshot(new Ref($tenant,Connection::text(Data::object($raw),'id')));
            if ($snapshot->tuple->hash($this->hasher)->value !== $tupleHash->value) { throw new IntegrityConflict('Tuple index mismatch'); }
            if (Time::compare($snapshot->issuedAt,$now) > 0) { continue; }
            if ($latest === null || Time::compare($snapshot->issuedAt,$latest->issuedAt) > 0
                || (Time::compare($snapshot->issuedAt,$latest->issuedAt) === 0 && $snapshot->decision !== 'qualified')) { $latest = $snapshot; }
        }
        return $latest;
    }
    public function putPolicy(RolloutPolicy $policy): void
    {
        $this->authorize($policy->ref->tenant,'qualification.policy.put');
        $this->snapshot($policy->stable);
        if ($policy->canary !== null) { $this->snapshot($policy->canary); }
        $this->db->transaction(function () use ($policy): void {
            $this->insert('qualification_policies',$policy->ref,$policy->toArray(),['stable_id'=>$policy->stable->value,'canary_id'=>$policy->canary?->value]);
        });
    }
    public function policy(Ref $id): RolloutPolicy
    {
        $this->authorize($id->tenant,'qualification.read'); $data = $this->load('qualification_policies',$id);
        if ($data === null) { throw new IntegrityConflict('Qualification rollout missing'); }
        $policy = RolloutPolicy::fromArray($id->tenant,$data); $id->assertSame($policy->ref); return $policy;
    }

    /**
     * Publish only affects future sessions; audit and head CAS commit together.
     * @phpstan-impure Reads current guards and changes durable publication state.
     */
    public function publish(Ref $scope, Ref $command, Ref $policyId, int $expected, UtcInstant $now,
        SelectionGate $gate, Compatibility $compatibility): int
    {
        $this->authorize($scope->tenant,'qualification.publish'); $scope->tenant->assertSame($command->tenant); $scope->tenant->assertSame($policyId->tenant);
        if ($expected < 0 || $expected >= 9007199254740991) { throw new \InvalidArgumentException('Bounded expected publication revision required'); }
        return $this->db->transaction(function () use ($scope,$command,$policyId,$expected,$now,$gate,$compatibility): int {
            $this->lock($command->tenant,'publish-command',$command->value); $this->lock($scope->tenant,'rollout-scope',$scope->value);
            $policy = $this->policy($policyId); $stable = $this->usable($policy->stable,$now,$gate);
            $candidate = $policy->canary === null ? null : $this->usable($policy->canary,$now,$gate);
            $input = ['scope'=>$scope->value,'policy'=>$policy->toArray(),'expected'=>$expected];
            $hash = $this->hasher->hash($scope->tenant,'qualification-publication',$input)->value;
            $saved = $this->load('qualification_audit',$command);
            if ($saved !== null) {
                if (Data::text($saved,'input_hash') !== $hash) { throw new IntegrityConflict('Publication command hash conflict'); }
                return Data::integer($saved,'revision');
            }
            $head = $this->head($scope);
            if (($head === null ? 0 : $head['revision']) !== $expected) { throw new ContractViolation(ErrorCode::OptimisticConflict); }
            if ($candidate !== null) { $compatibility->assertCompatible($stable,$candidate,$policy->reason); }
            if ($head !== null) {
                $old = $this->policy(new Ref($scope->tenant,$head['policy']));
                $compatibility->assertCompatible($this->snapshot($old->stable),$stable,$policy->reason);
            }
            if ($policy->rollbackOf !== null) {
                if ($head === null || $head['policy'] !== $policy->rollbackOf->value) { throw new IntegrityConflict('Rollback origin mismatch'); }
                $prior = $this->db->row('SELECT id FROM '.$this->db->table('qualification_audit').'
                    WHERE tenant_id=:tenant AND scope_id=:scope AND stable_id=:stable AND revision<:revision LIMIT 1',
                    ['tenant'=>$scope->tenant->value,'scope'=>$scope->value,'stable'=>$stable->ref->value,'revision'=>$head['revision']]);
                if ($prior === null) { throw new IntegrityConflict('Rollback target has no prior published qualification'); }
                $this->load('qualification_audit',new Ref($scope->tenant,Connection::text($prior,'id')));
            }
            $revision = $expected + 1;
            $this->db->execute('INSERT INTO '.$this->db->table('qualification_heads').' (tenant_id,id,revision,policy_id)
                VALUES(:tenant,:id,:revision,:policy) ON CONFLICT(tenant_id,id) DO UPDATE SET revision=EXCLUDED.revision,policy_id=EXCLUDED.policy_id',
                ['tenant'=>$scope->tenant->value,'id'=>$scope->value,'revision'=>$revision,'policy'=>$policyId->value]);
            $this->insert('qualification_audit',$command,['input_hash'=>$hash,'scope'=>$scope->value,'revision'=>$revision,
                'policy'=>$policyId->value,'stable_snapshot'=>$stable->ref->value,'canary_snapshot'=>$candidate?->ref->value,
                'reason'=>$policy->reason->value,'rollback_of'=>$policy->rollbackOf?->value,'active_pins_changed'=>0,'replay_effects'=>false],
                ['scope_id'=>$scope->value,'revision'=>$revision,'stable_id'=>$stable->ref->value]);
            return $revision;
        });
    }

    /** @return array{revision:int,policy:string}|null */
    public function head(Ref $scope): ?array
    {
        $this->authorize($scope->tenant,'qualification.read');
        $row = $this->db->row('SELECT revision,policy_id FROM '.$this->db->table('qualification_heads').' WHERE tenant_id=:tenant AND id=:id',
            ['tenant'=>$scope->tenant->value,'id'=>$scope->value]);
        return $row === null ? null : ['revision'=>Connection::number($row,'revision'),'policy'=>Connection::text($row,'policy_id')];
    }

    /** A replay revalidates its OLD snapshot; never silently selects the latest rollout. */
    public function pin(Ref $scope, Ref $session, ?Measurements $measurements, UtcInstant $now, SelectionGate $gate): Assignment
    {
        $this->authorize($session->tenant,'qualification.pin'); $session->tenant->assertSame($scope->tenant);
        return $this->db->transaction(function () use ($scope,$session,$measurements,$now,$gate): Assignment {
            $this->lock($session->tenant,'pin-session',$session->value);
            $saved = $this->load('qualification_pins',$session);
            if ($saved !== null) {
                if (Data::text($saved,'scope') !== $scope->value) { throw new IntegrityConflict('Session rollout scope conflict'); }
                $assignment = Assignment::fromArray($session->tenant,Data::object($saved['assignment'] ?? null));
                $session->assertSame($assignment->session); $snapshot = $this->usable($assignment->snapshot,$now,$gate);
                if ($assignment->tupleHash->value !== $snapshot->tuple->hash($this->hasher)->value) { throw new IntegrityConflict('Pinned tuple mismatch'); }
                return $assignment;
            }
            $this->lock($scope->tenant,'rollout-scope',$scope->value); $head = $this->head($scope);
            if ($head === null) { throw new ContractViolation(ErrorCode::DeploymentUnavailable); }
            $policy = $this->policy(new Ref($scope->tenant,$head['policy']));
            $this->lock($scope->tenant,'canary-policy',$policy->ref->value);
            $count = $this->db->row('SELECT count(*) AS admissions FROM '.$this->db->table('qualification_pins').'
                WHERE tenant_id=:tenant AND policy_id=:policy AND is_canary=true',['tenant'=>$session->tenant->value,'policy'=>$policy->ref->value]);
            if ($count === null) { throw new IntegrityConflict('Canary capacity unknown'); }
            $stable = $this->snapshot($policy->stable); $canary = $policy->canary === null ? null : $this->snapshot($policy->canary);
            $assignment = $policy->choose($session,$stable,$canary,$measurements,Connection::number($count,'admissions'),$now,$this->hasher);
            $this->usable($assignment->snapshot,$now,$gate);
            $this->insert('qualification_pins',$session,['scope'=>$scope->value,'assignment'=>$assignment->toArray(),
                'measurements'=>$measurements?->toArray(),'reason_ref'=>$policy->reason->value],
                ['policy_id'=>$assignment->policy->value,'snapshot_id'=>$assignment->snapshot->value,'is_canary'=>$assignment->canary ? 'true' : 'false']);
            return $assignment;
        });
    }

    private function usable(Ref $id, UtcInstant $now, SelectionGate $gate): Snapshot
    {
        $snapshot = $this->snapshot($id); $latest = $this->latest($id->tenant,$snapshot->tuple->hash($this->hasher),$now);
        if (!$snapshot->permits($snapshot->tuple,$snapshot->policy,$now,$this->hasher) || $latest === null
            || !$latest->permits($snapshot->tuple,$snapshot->policy,$now,$this->hasher)) { throw new ContractViolation(ErrorCode::DeploymentUnavailable); }
        $gate->authorize($snapshot,$now); return $snapshot;
    }
    private function authorize(TenantId $tenant, string $operation): void
    { $tenant->assertSame($this->actor->tenant); $this->authority->authorize($tenant,$this->actor,$operation); }
    private function lock(TenantId $tenant, string $kind, string $id): void
    { $this->db->execute('SELECT pg_advisory_xact_lock(hashtextextended(:key,0))',['key'=>json_encode([$tenant->value,$kind,$id],JSON_THROW_ON_ERROR)]); }
    /**
     * @param array<string,mixed> $document
     * @param array<string,scalar|null> $columns
     */
    private function insert(string $table, Ref $id, array $document, array $columns): void
    {
        $hash = $this->hasher->hash($id->tenant,'qualification-record',['table'=>$table,'id'=>$id->value,'document'=>$document])->value;
        $parameters = ['tenant'=>$id->tenant->value,'id'=>$id->value,'hash'=>$hash,'document'=>json_encode($document,JSON_THROW_ON_ERROR)] + $columns;
        $names = array_keys($columns); $fields = $names === [] ? '' : ','.implode(',',$names); $values = $names === [] ? '' : ',:'.implode(',:',$names);
        $this->db->execute('INSERT INTO '.$this->db->table($table).' (tenant_id,id,content_hash,document_json'.$fields.')
            VALUES(:tenant,:id,:hash,:document'.$values.') ON CONFLICT(tenant_id,id) DO NOTHING',$parameters);
        $saved = $this->load($table,$id);
        if ($saved === null || $this->hasher->hash($id->tenant,'qualification-record',['table'=>$table,'id'=>$id->value,'document'=>$saved])->value !== $hash) {
            throw new IntegrityConflict('Qualification immutable identity hash conflict');
        }
    }
    /** @return array<string,mixed>|null */
    private function load(string $table, Ref $id): ?array
    {
        $row = $this->db->row('SELECT content_hash,document_json FROM '.$this->db->table($table).' WHERE tenant_id=:tenant AND id=:id',
            ['tenant'=>$id->tenant->value,'id'=>$id->value]);
        if ($row === null) { return null; }
        $data = Data::object(json_decode(Connection::text($row,'document_json'),true,64,JSON_THROW_ON_ERROR));
        $hash = $this->hasher->hash($id->tenant,'qualification-record',['table'=>$table,'id'=>$id->value,'document'=>$data]);
        if ($hash->value !== Connection::text($row,'content_hash')) { throw new IntegrityConflict('Qualification record hash mismatch'); }
        return $data;
    }
}
