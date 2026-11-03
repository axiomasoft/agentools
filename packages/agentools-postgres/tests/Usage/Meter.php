<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Postgres\Tests\Usage;

use AgenToolsPlatform\Core\Usage\UsageEvent;
use AgenToolsPlatform\Postgres\Usage\{ExportStatus, MeteringPort};

final class Meter implements MeteringPort
{
    public bool $lost=false;
    public bool $unavailable=false;
    public ?\Closure $afterCommit=null;
    public function __construct(private Fixture $f) {}
    public function status(UsageEvent $event): ExportStatus
    {
        $this->f->usage->db->assertIdle();
        if ($this->unavailable) { throw new \RuntimeException('secret prompt'); }
        $row=$this->f->rig->sql('SELECT hash FROM host_meter WHERE tenant=:tenant AND id=:id',
            ['tenant'=>$event->id->tenant->value,'id'=>$event->id->value])->fetch(\PDO::FETCH_ASSOC);
        return new ExportStatus($row===false?'not_found':'exported',$row===false?$event->hash->value:$row['hash'],$row===false?null:'metering-ack');
    }
    public function export(UsageEvent $event): ExportStatus
    {
        $this->f->usage->db->assertIdle();
        $this->f->rig->sql('INSERT INTO host_meter(tenant,id,hash,document) VALUES (:tenant,:id,:hash,CAST(:document AS jsonb))
            ON CONFLICT (tenant,id) DO UPDATE SET calls=host_meter.calls+1',
            ['tenant'=>$event->id->tenant->value,'id'=>$event->id->value,'hash'=>$event->hash->value,'document'=>json_encode($event->toArray(),JSON_THROW_ON_ERROR)]);
        if ($this->afterCommit!==null) { ($this->afterCommit)(); }
        if ($this->lost) { throw new \RuntimeException('secret provider reply'); }
        return new ExportStatus('exported',$event->hash->value,'metering-ack');
    }
}
