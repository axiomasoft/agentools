<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Confirmation;

use AgenTools\Action\{ActionReducer, Actor, FoldedAction, JournalHashChain};
use AgenTools\Persistence\{EventStoreSupport, UncommittedEvent};
use AgenTools\Schema\Canonicalizer;
use AgenToolsPlatform\Core\Common\{ActionId, IntegrityConflict};
use AgenToolsPlatform\Postgres\Actions\Projection;
use AgenToolsPlatform\Postgres\Runtime\{Connection, RepositoryContext};

/** Confirmation uses the existing dated journal, reducer, projection and outbox. */
final readonly class Journal
{
    public function __construct(private RepositoryContext $environment) {}

    public function read(ActionId $id, bool $lock = false): FoldedAction
    {
        $db = $this->environment->db;
        $saved = $db->row('SELECT state,digest,version FROM ' . $db->table('action_current') .
            ' WHERE tenant_id=:tenant AND id=:id' . ($lock ? ' FOR UPDATE' : ''), ['tenant'=>$id->tenant->value,'id'=>$id->value]);
        $reducer = new ActionReducer(hashChain: new JournalHashChain(new Canonicalizer()));
        $state = new FoldedAction();
        do {
            $page = $db->journal()->load($id->tenant->value, $id->value, $state->seq, 1000);
            foreach ($page->events as $event) {
                $row = $db->row('SELECT content_hash FROM ' . $db->table('agentools_events') . ' WHERE tenant_id=:tenant AND event_id=:id',
                    ['tenant'=>$id->tenant->value,'id'=>$event->eventId]);
                $hash = (new EventStoreSupport())->contentHash($id->tenant->value, $id->value,
                    new UncommittedEvent($event->eventId,$event->kind,$event->eventVersion,$event->specRevision,$event->actor,$event->timestamp,$event->payload,$event->epoch));
                if ($row === null || !hash_equals(Connection::text($row,'content_hash'),$hash)) {
                    throw new IntegrityConflict('Confirmation journal integrity conflict');
                }
                $state = $reducer->apply($state,$event);
            }
        } while ($page->hasMore);
        $projection = new Projection($state);
        if ($saved === null || Connection::number($saved,'version') !== $state->seq
            || !hash_equals(Connection::text($saved,'digest'),$projection->digest())
            || (new Canonicalizer())->canonicalize(json_decode(Connection::text($saved,'state'),false,512,JSON_THROW_ON_ERROR)) !== $projection->json()) {
            throw new IntegrityConflict('Confirmation requires a verified current projection');
        }
        return $state;
    }

    /**
     * Caller owns the transaction and locked current row.
     * @param list<array{kind:string,payload:array<string,mixed>,epoch?:int}> $specs
     */
    public function append(ActionId $id, FoldedAction $state, array $specs): FoldedAction
    {
        $db=$this->environment->db;
        $now=$db->row('SELECT to_char(clock_timestamp() AT TIME ZONE \'UTC\',\'YYYY-MM-DD"T"HH24:MI:SS.US"Z"\') AS ts')
            ?? throw new IntegrityConflict('Missing database time');
        $events=[];
        foreach ($specs as $index=>$spec) {
            $eventId=substr($this->environment->hasher->hash($id->tenant,'confirmation-event',[$id->value,$state->seq+$index+1,$spec['kind']])->value,8);
            $events[]=new UncommittedEvent($eventId,$spec['kind'],1,'2026-09',new Actor('host','confirmation'),
                Connection::text($now,'ts'),$spec['payload'],$spec['epoch']??null);
        }
        $db->journal()->appendInTransaction($id->tenant->value,$id->value,$state->seq,$events);
        $reducer=new ActionReducer(hashChain:new JournalHashChain(new Canonicalizer()));
        foreach ($db->journal()->load($id->tenant->value,$id->value,$state->seq,1000)->events as $event) { $state=$reducer->apply($state,$event); }
        $projection=new Projection($state);
        $db->execute('UPDATE '.$db->table('action_current').' SET version=:version,state=CAST(:state AS jsonb),digest=:digest WHERE tenant_id=:tenant AND id=:id',
            ['version'=>$state->seq,'state'=>$projection->json(),'digest'=>$projection->digest(),'tenant'=>$id->tenant->value,'id'=>$id->value]);
        foreach ($events as $event) {
            $db->execute('INSERT INTO '.$db->table('outbox').' (tenant_id,id,event_key,payload_hash,event_type,aggregate_ref,deadline_at,retention_until)
                SELECT :tenant,:id,:key,:hash,\'action.journaled\',:action,deadline_at,deadline_at+interval \'7 days\'
                FROM '.$db->table('action_submissions').' WHERE tenant_id=:source_tenant AND id=:source_action',
                ['tenant'=>$id->tenant->value,'id'=>'action.event:'.$event->eventId,'key'=>'action.event:'.$event->eventId,
                    'hash'=>substr((new EventStoreSupport())->contentHash($id->tenant->value,$id->value,$event),8),'action'=>$id->value,
                    'source_tenant'=>$id->tenant->value,'source_action'=>$id->value]);
        }
        return $state;
    }
}
