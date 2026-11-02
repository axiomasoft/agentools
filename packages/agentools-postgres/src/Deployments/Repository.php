<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Deployments;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, ContractViolation, ErrorCode, IntegrityConflict, PayloadHash, Ref, TenantId, UtcInstant};
use AgenToolsPlatform\Core\Models\Routing\{AdminState, Catalog, CatalogEntry, CatalogView, DeploymentState, EmergencyRule, FallbackPlan, Health, Qualification, RouteDecision};
use AgenToolsPlatform\Postgres\Runtime\Connection;

/** Host-scoped repository. Immutable identities conflict on differing hashes; live state uses CAS. */
final readonly class Repository implements Catalog
{
    public function __construct(private Connection $db, private CanonicalHasher $hasher, private Authority $authority,
        private Ref $actor) {}

    public function put(CatalogEntry $entry): void
    {
        $tenant = $entry->deployment->ref->tenant; $this->authorize($tenant, 'catalog.put');
        $this->immutable('deployment_catalog', $tenant, $entry->deployment->ref->value, $entry->toArray());
    }
    public function state(Ref $deployment, int $expectedVersion, AdminState $admin, Health $health): int
    {
        $this->authorize($deployment->tenant, 'catalog.state');
        return $this->db->transaction(function () use ($deployment,$expectedVersion,$admin,$health): int {
            $row = $this->db->row('UPDATE '.$this->db->table('deployment_catalog').'
                SET admin=:admin, health=:health, state_version=state_version+1
                WHERE tenant_id=:tenant AND id=:id AND state_version=:version RETURNING state_version',
                ['tenant'=>$deployment->tenant->value,'id'=>$deployment->value,'version'=>$expectedVersion,'admin'=>$admin->value,'health'=>$health->value]);
            if ($row === null) { throw new ContractViolation(ErrorCode::OptimisticConflict); }
            return Connection::number($row,'state_version');
        });
    }
    public function qualify(Ref $deployment, Qualification $q): void
    {
        $deployment->tenant->assertSame($q->ref->tenant); $this->authorize($deployment->tenant, 'catalog.qualify');
        $data = ['deployment'=>$deployment->value,'tuple_hash'=>$q->tupleHash->value,'decision'=>$q->decision,
            'issued_at'=>$q->issuedAt->value,'expires_at'=>$q->expiresAt?->value,'evidence'=>$q->evidence->value];
        $this->immutable('deployment_qualifications',$deployment->tenant,$q->ref->value,$data,$deployment->value);
    }
    /** Emergency selectors and evidence are immutable; lifting is an authorized, audited CAS operation. */
    public function block(TenantId $tenant, EmergencyRule $rule): void
    {
        $this->authorize($tenant, 'catalog.emergency');
        if ($rule->tenant !== null && $rule->tenant !== $tenant->value) { throw new ContractViolation(ErrorCode::AccessDenied); }
        $this->immutable('deployment_emergencies',$tenant,$rule->id,self::object(get_object_vars($rule)));
    }
    public function lift(TenantId $tenant, string $id, int $expectedVersion, Ref $evidence): void
    {
        $tenant->assertSame($evidence->tenant); $this->authorize($tenant, 'catalog.emergency.lift');
        $this->db->transaction(function () use ($tenant,$id,$expectedVersion,$evidence): void {
            $row = $this->db->row('UPDATE '.$this->db->table('deployment_emergencies').'
                SET active=false, state_version=state_version+1
                WHERE tenant_id=:tenant AND id=:id AND state_version=:version AND active=true RETURNING id',
                ['tenant'=>$tenant->value,'id'=>$id,'version'=>$expectedVersion]);
            if ($row === null) { throw new ContractViolation(ErrorCode::OptimisticConflict); }
            // Same transaction as the state change; audit failure rolls back the lift.
            $data = ['emergency'=>$id,'version'=>$expectedVersion,'evidence'=>$evidence->value];
            $traceId = 'emergency-lift:'.$id.':'.$expectedVersion;
            $hash = $this->hasher->hash($tenant,'deployment-record',['table'=>'deployment_traces','id'=>$traceId,'data'=>$data])->value;
            $this->db->execute('INSERT INTO '.$this->db->table('deployment_traces').' (tenant_id,id,content_hash,document_json)
                VALUES (:tenant,:id,:hash,:json)', ['tenant'=>$tenant->value,'id'=>$traceId,
                'hash'=>$hash,'json'=>json_encode($data,JSON_THROW_ON_ERROR)]);
        });
    }
    public function record(Ref $event, RouteDecision $decision): void
    {
        $this->authorize($event->tenant, 'catalog.trace');
        $this->immutable('deployment_traces',$event->tenant,$event->value,$this->trace($decision));
    }
    public function recordFallback(FallbackPlan $plan): void
    {
        $origin = $plan->origin; $this->authorize($origin->invocation->tenant, 'catalog.trace');
        $this->immutable('deployment_traces',$origin->invocation->tenant,$origin->failureEvent->value,[
            'outcome'=>$plan->outcome,'origin'=>$origin->invocation->value,'bundle'=>$origin->bundle->value,'goal'=>$origin->goal->value,
            'actions'=>array_map(static fn (Ref $r): string => $r->value,$origin->actions),
            'disclosed_segment'=>$origin->disclosedSegment?->value,'unknown_effect'=>$origin->unknownEffect?->value,
            'invocation'=>$plan->invocation?->value,'segment'=>$plan->segment?->value,'retry_actions'=>false,
            'route'=>$plan->route === null ? null : $this->trace($plan->route)]);
    }
    public function view(TenantId $tenant): CatalogView
    {
        $this->authorize($tenant, 'catalog.read'); $states = [];
        $rows = $this->db->execute('SELECT * FROM '.$this->db->table('deployment_catalog').' WHERE tenant_id=:tenant ORDER BY id', ['tenant'=>$tenant->value]);
        foreach ($rows->fetchAll(\PDO::FETCH_ASSOC) as $raw) {
            $row = self::object($raw);
            $id = Connection::text($row,'id'); $data = $this->verified('deployment_catalog',$tenant,$row);
            $entry = CatalogEntry::fromArray($data,$this->hasher);
            if ($entry->deployment->ref->value !== $id || $entry->deployment->ref->tenant->value !== $tenant->value) { throw new IntegrityConflict('Catalog binding mismatch'); }
            $qs = [];
            $qrows = $this->db->execute('SELECT * FROM '.$this->db->table('deployment_qualifications').' WHERE tenant_id=:tenant AND deployment_id=:deployment ORDER BY id', ['tenant'=>$tenant->value,'deployment'=>$id]);
            foreach ($qrows->fetchAll(\PDO::FETCH_ASSOC) as $rawQualification) {
                $qrow = self::object($rawQualification);
                $q = $this->verified('deployment_qualifications',$tenant,$qrow);
                if ($q['deployment'] !== $id) { throw new IntegrityConflict('Qualification binding mismatch'); }
                $qs[] = new Qualification(new Ref($tenant,Connection::text($qrow,'id')), new PayloadHash(self::string($q,'tuple_hash')),
                    self::string($q,'decision'),new UtcInstant(self::string($q,'issued_at')),
                    $q['expires_at'] === null ? null : new UtcInstant(self::string($q,'expires_at')),new Ref($tenant,self::string($q,'evidence')));
            }
            $states[] = new DeploymentState($entry,AdminState::from(Connection::text($row,'admin')),Health::from(Connection::text($row,'health')),Connection::number($row,'state_version'),$qs);
        }
        $rules = [];
        $rows = $this->db->execute('SELECT * FROM '.$this->db->table('deployment_emergencies').' WHERE tenant_id=:tenant AND active=true ORDER BY id', ['tenant'=>$tenant->value]);
        foreach ($rows->fetchAll(\PDO::FETCH_ASSOC) as $raw) {
            $row = self::object($raw);
            $r = $this->verified('deployment_emergencies',$tenant,$row);
            $audio = $r['audio'] ?? null;
            if ($audio !== null && !is_bool($audio)) { throw new IntegrityConflict('Invalid emergency audio target'); }
            $rules[] = new EmergencyRule(self::string($r,'id'),self::string($r,'evidenceRef'),
                self::optional($r,'provider'),self::optional($r,'account'),self::optional($r,'deployment'),
                self::optional($r,'model'),self::optional($r,'region'),self::optional($r,'tenant'),self::optional($r,'task'),$audio);
        }
        return new CatalogView($states,$rules);
    }
    private function authorize(TenantId $tenant, string $operation): void
    { $tenant->assertSame($this->actor->tenant); $this->authority->authorize($tenant,$this->actor,$operation); }
    /** @param array<string,mixed> $data */
    private function immutable(string $table, TenantId $tenant, string $id, array $data, ?string $deployment = null): void
    {
        $hash = $this->hasher->hash($tenant,'deployment-record',['table'=>$table,'id'=>$id,'data'=>$data])->value;
        $this->db->transaction(function () use ($table,$tenant,$id,$data,$deployment,$hash): void {
            $params = ['tenant'=>$tenant->value,'id'=>$id,'hash'=>$hash,'json'=>json_encode($data,JSON_THROW_ON_ERROR)];
            if ($deployment !== null) { $params['deployment'] = $deployment; }
            $this->db->execute('INSERT INTO '.$this->db->table($table).' (tenant_id,id,content_hash,document_json'.($deployment === null ? '' : ',deployment_id').')
                VALUES (:tenant,:id,:hash,:json'.($deployment === null ? '' : ',:deployment').') ON CONFLICT (tenant_id,id) DO NOTHING',$params);
            $row = $this->db->row('SELECT * FROM '.$this->db->table($table).' WHERE tenant_id=:tenant AND id=:id',['tenant'=>$tenant->value,'id'=>$id]);
            if ($row === null || Connection::text($row,'content_hash') !== $hash) { throw new IntegrityConflict('Deployment record identity conflict'); }
            $this->verified($table,$tenant,$row);
        });
    }
    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function verified(string $table, TenantId $tenant, array $row): array
    {
        $data = self::object(json_decode(Connection::text($row,'document_json'),true,64,JSON_THROW_ON_ERROR));
        $hash = $this->hasher->hash($tenant,'deployment-record',['table'=>$table,'id'=>Connection::text($row,'id'),'data'=>$data]);
        if ($hash->value !== Connection::text($row,'content_hash')) { throw new IntegrityConflict('Deployment record hash mismatch'); }
        return $data;
    }
    /** @return array<string,mixed> */
    private function trace(RouteDecision $decision): array
    {
        return ['outcome'=>$decision->outcome(),'selected'=>$decision->selected?->deployment->ref->value,
            'catalog_hash'=>$decision->selected?->hash->value,'rejected'=>array_map(static fn (array $reasons): array => array_map(static fn ($reason): string => $reason->value,$reasons),$decision->rejected)];
    }
    /** @param array<string,mixed> $data */
    private static function string(array $data, string $key): string
    { return Connection::text($data,$key); }
    /** @param array<string,mixed> $data */
    private static function optional(array $data, string $key): ?string
    { return ($data[$key] ?? null) === null ? null : self::string($data,$key); }
    /** @return array<string,mixed> */
    private static function object(mixed $data): array
    {
        if (!is_array($data)) { throw new IntegrityConflict('Invalid deployment document'); }
        foreach ($data as $key=>$_) { if (!is_string($key)) { throw new IntegrityConflict('Invalid deployment document keys'); } }
        return $data;
    }
}
