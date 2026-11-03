<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Postgres\Usage;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, Ref, TenantId};
use AgenToolsPlatform\Core\Usage\{UsageConflict, UsageEvent, UsageFold, UsagePort, Values};
use AgenToolsPlatform\Postgres\Runtime\Connection;

/** Tenant-bound immutable facts and durable export. No prices, debit or balances. */
final readonly class Repository implements UsagePort
{
    public function __construct(public Connection $db, public TenantId $tenant,
        private CanonicalHasher $hasher, public BacklogPolicy $policy) {}

    public function append(UsageEvent $event): string
    {
        $ok = $this->db->transaction(fn (): bool => $this->appendInTransaction($event));
        if (!$ok) { throw new UsageConflict(); }
        return $event->acknowledgement();
    }

    /** Caller owns this exact DB transaction. False must be committed to preserve quarantine. */
    public function appendInTransaction(UsageEvent $event): bool
    {
        $this->tenant->assertSame($event->id->tenant); $db = $this->db;
        $this->lockTenant();
        $rows = $db->execute('SELECT document FROM '.$db->table('accounting_usage').'
            WHERE tenant_id=:tenant AND (invocation_id=:invocation OR id=:id) ORDER BY usage_version',
            ['tenant'=>$this->tenant->value,'invocation'=>$event->invocation->value,'id'=>$event->id->value])->fetchAll(\PDO::FETCH_ASSOC);
        $events = [];
        foreach ($rows as $row) {
            $prior = $this->decode(Connection::text(Values::object($row),'document'));
            if ($prior->id->value === $event->id->value || ($prior->invocation->value === $event->invocation->value && $prior->version === $event->version)) {
                if ($prior->hash->value === $event->hash->value) { return true; }
                $this->quarantine($event,$prior); return false;
            }
            if ($prior->invocation->value === $event->invocation->value) { $events[] = $prior; }
        }
        // Reject branching, changed producer/binding and missing predecessors before any insertion.
        try { UsageFold::fold([...$events,$event]); }
        catch (UsageConflict) {
            $prior = $events[count($events)-1] ?? throw new UsageConflict();
            $this->quarantine($event,$prior); return false;
        }
        $db->execute('INSERT INTO '.$db->table('accounting_usage').'
            (tenant_id,id,invocation_id,usage_version,payload_hash,document,supersedes)
            VALUES (:tenant,:id,:invocation,:version,:hash,CAST(:document AS jsonb),:prior)',
            ['tenant'=>$this->tenant->value,'id'=>$event->id->value,'invocation'=>$event->invocation->value,
                'version'=>$event->version,'hash'=>$event->hash->value,'document'=>json_encode($event->toArray(),JSON_THROW_ON_ERROR),'prior'=>$event->supersedes]);
        $db->execute('INSERT INTO '.$db->table('accounting_export').'(tenant_id,id) VALUES (:tenant,:id)',
            ['tenant'=>$this->tenant->value,'id'=>$event->id->value]);
        return true;
    }

    public function event(Ref $id): UsageEvent
    {
        $this->tenant->assertSame($id->tenant);
        $row = $this->db->row('SELECT u.document FROM '.$this->db->table('accounting_usage').' u
            JOIN '.$this->db->table('accounting_export').' x USING (tenant_id,id)
            WHERE u.tenant_id=:tenant AND u.id=:id AND x.status<>\'quarantined\'', ['tenant'=>$this->tenant->value,'id'=>$id->value]);
        if ($row === null) { throw new AccountingPending(); }
        return $this->decode(Connection::text($row,'document'));
    }

    /** Inside a tenant-serialized admission transaction. Existing in-flight work is always retained. */
    public function assertCapacity(): void
    {
        $this->lockTenant();
        $row = $this->db->row('SELECT
            (SELECT count(*) FROM '.$this->db->table('accounting_export').' WHERE tenant_id=:tenant AND status<>\'exported\') +
            (SELECT count(*) FROM '.$this->db->table('accounting_reservation').' r WHERE r.tenant_id=:tenant2
                AND r.status NOT IN (\'settled\',\'released\') AND NOT EXISTS
                (SELECT 1 FROM '.$this->db->table('accounting_usage').' u WHERE u.tenant_id=r.tenant_id
                 AND u.invocation_id=COALESCE(r.request->>\'invocation_ref\',r.request->\'plan\'->>\'invocation_id\'))) AS pending,
            EXISTS (SELECT 1 FROM '.$this->db->table('accounting_export').' WHERE tenant_id=:tenant3 AND status<>\'exported\'
                AND created_at < clock_timestamp()-make_interval(secs=>:age)) AS too_old',
            ['tenant'=>$this->tenant->value,'tenant2'=>$this->tenant->value,'tenant3'=>$this->tenant->value,'age'=>$this->policy->maxAgeSeconds]);
        if ($row === null || Connection::number($row,'pending') >= $this->policy->maxPending || $row['too_old'] === true) { throw new AccountingPending(); }
    }

    public function lockTenant(): void
    {
        $this->db->assertTransaction();
        $this->db->execute('SELECT pg_advisory_xact_lock(hashtextextended(:key,0))',
            ['key'=>$this->db->table('accounting_usage').':'.$this->tenant->value]);
    }

    /** @return array<string,mixed> */
    public function exportState(Ref $id): array
    {
        $this->tenant->assertSame($id->tenant);
        return $this->db->row('SELECT status,epoch,attempts,ack_hash FROM '.$this->db->table('accounting_export').' WHERE tenant_id=:tenant AND id=:id',
            ['tenant'=>$this->tenant->value,'id'=>$id->value]) ?? throw new AccountingPending();
    }

    private function decode(string $json): UsageEvent
    {
        $d = Values::object(json_decode($json,true,flags:JSON_THROW_ON_ERROR));
        return UsageEvent::fromArray($d,$this->hasher);
    }

    private function quarantine(UsageEvent $incoming, UsageEvent $accepted): void
    {
        $db = $this->db;
        $db->execute('INSERT INTO '.$db->table('accounting_quarantine').'(tenant_id,id,incoming_hash,accepted_hash,code)
            VALUES (:tenant,:id,:incoming,:accepted,\'USAGE_PAYLOAD_CONFLICT\') ON CONFLICT DO NOTHING',
            ['tenant'=>$this->tenant->value,'id'=>$incoming->id->value,'incoming'=>$incoming->hash->value,'accepted'=>$accepted->hash->value]);
        $db->execute('UPDATE '.$db->table('accounting_export').' SET status=\'quarantined\',version=version+1,epoch=epoch+1,updated_at=clock_timestamp()
            WHERE tenant_id=:tenant AND id IN (SELECT id FROM '.$db->table('accounting_usage').'
                WHERE tenant_id=:tenant2 AND invocation_id=:invocation)',
            ['tenant'=>$this->tenant->value,'tenant2'=>$this->tenant->value,'invocation'=>$accepted->invocation->value]);
    }
}
