<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Laravel\Observability;

use AgenToolsPlatform\Core\Common\{IntegrityConflict,Ref};
use AgenToolsPlatform\Core\Observability\{AuditSink,Event};
use AgenToolsPlatform\Laravel\Managed\ProductionBinding;
use AgenToolsPlatform\Postgres\Runtime\{Connection,RepositoryContext};

/** Separate append-only metadata journal. Normal user data remains owned by its existing journals. */
final readonly class AuditJournal implements AuditSink,ProductionBinding
{
    public function __construct(private RepositoryContext $environment) {}
    public function assertProduction(): void { $this->environment->db->assertIdle(); }
    public function append(Event $event): Ref
    {
        $this->environment->authorize($event->context());
        $db=$this->environment->db;
        $db->transaction(function () use ($db,$event): void {
            $keys=['tenant'=>$event->id->tenant->value,'id'=>$event->id->value];
            $db->execute('INSERT INTO '.$db->table('observability_events').' (tenant_id,id,event_hash,document)
                VALUES (:tenant,:id,:hash,CAST(:document AS jsonb)) ON CONFLICT DO NOTHING',
                $keys+['hash'=>$event->hash->value,'document'=>json_encode($event->metadata(),JSON_THROW_ON_ERROR)]);
            $row=$db->row('SELECT event_hash FROM '.$db->table('observability_events').' WHERE tenant_id=:tenant AND id=:id',$keys);
            if ($row===null || Connection::text($row,'event_hash')!==$event->hash->value) { throw new IntegrityConflict('Audit identity conflict'); }
        });
        return $event->id;
    }
    public function reset(): void { $this->environment->db->assertIdle(); }
}
