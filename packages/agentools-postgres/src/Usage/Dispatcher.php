<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Postgres\Usage;

use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Postgres\Runtime\Connection;

/** Scanner owns only export attempts, never invocation execution. External IO is outside Tx. */
final readonly class Dispatcher
{
    public function __construct(private Repository $repository, private MeteringPort $owner) {}

    public function assertScope(\AgenToolsPlatform\Core\Common\TenantId $tenant, Connection $connection): void
    {
        $tenant->assertSame($this->repository->tenant);
        if ($this->repository->db !== $connection) { throw new \InvalidArgumentException('Worker reset must cover the dispatcher connection'); }
    }

    public function dispatchOne(): bool
    {
        $r=$this->repository; $db=$r->db; $db->assertIdle();
        $claim=$db->transaction(function () use ($r,$db): ?array {
            $row=$db->row('SELECT x.id,x.epoch,x.attempts FROM '.$db->table('accounting_export').' x
                JOIN '.$db->table('accounting_usage').' u USING (tenant_id,id)
                WHERE x.tenant_id=:tenant AND x.status IN (\'pending\',\'unknown\',\'claimed\') AND x.next_at<=clock_timestamp()
                AND (x.lease_until IS NULL OR x.lease_until<=clock_timestamp())
                AND (u.supersedes IS NULL OR EXISTS (SELECT 1 FROM '.$db->table('accounting_export').' p
                    WHERE p.tenant_id=u.tenant_id AND p.id=u.supersedes AND p.status=\'exported\'))
                ORDER BY u.created_at,u.usage_version FOR UPDATE OF x SKIP LOCKED LIMIT 1',['tenant'=>$r->tenant->value]);
            if ($row===null) { return null; }
            $manual=Connection::number($row,'attempts') >= $r->policy->maxAttempts;
            $db->execute('UPDATE '.$db->table('accounting_export').' SET status=:status,epoch=epoch+1,version=version+1,attempts=attempts+:increment,
                lease_until=clock_timestamp()+make_interval(secs=>:lease),updated_at=clock_timestamp() WHERE tenant_id=:tenant AND id=:id',
                ['status'=>$manual?'manual_resolution_required':'claimed','increment'=>$manual?0:1,'lease'=>$r->policy->leaseSeconds,'tenant'=>$r->tenant->value,'id'=>Connection::text($row,'id')]);
            return $manual ? null : ['id'=>Connection::text($row,'id'),'epoch'=>Connection::number($row,'epoch')+1];
        });
        if ($claim===null) { return false; }
        try {
            $event=$r->event(new Ref($r->tenant,$claim['id']));
            $db->assertIdle(); $reply=$this->owner->status($event);
            if ($reply->payloadHash!==$event->hash->value) { throw new AccountingPending(); }
            if ($reply->state==='not_found') { $db->assertIdle(); $reply=$this->owner->export($event); }
            if ($reply->state!=='exported' || $reply->payloadHash!==$event->hash->value) { throw new AccountingPending(); }
            $status='exported'; $ack=hash('sha256',$reply->acknowledgement ?? throw new AccountingPending());
        } catch (\Throwable) { $status='unknown'; $ack=null; }
        $db->transaction(function () use ($r,$db,$claim,$status,$ack): void {
            $db->execute('UPDATE '.$db->table('accounting_export').' SET status=:status,ack_hash=:ack,lease_until=NULL,
                next_at=clock_timestamp()+make_interval(secs=>:retry),version=version+1,updated_at=clock_timestamp()
                WHERE tenant_id=:tenant AND id=:id AND epoch=:epoch AND status=\'claimed\' AND lease_until>clock_timestamp()',
                ['status'=>$status,'ack'=>$ack,'retry'=>$r->policy->retrySeconds,'tenant'=>$r->tenant->value,'id'=>$claim['id'],'epoch'=>$claim['epoch']]);
        });
        return true;
    }
}
