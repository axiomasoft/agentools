<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Laravel\Operations;

use AgenToolsPlatform\Core\Common\{ContractViolation,ErrorCode};
use AgenToolsPlatform\Postgres\Runtime\{Connection,RepositoryContext};

/** Only allowlisted metadata columns. Never reads checkpoint/state_ref, payload bytes or secret handles. */
final readonly class Inspector
{
    public function __construct(private RepositoryContext $environment,private Authority $authority) {}
    /** @return list<Observation> */
    public function inspect(Command $c): array
    {
        $this->environment->authorize($c->context); $this->authority->authorize($c);
        $db=$this->environment->db;
        $keys=['tenant'=>$c->id->tenant->value,'id'=>$c->target,'session'=>$c->context->session->value];
        if ($c->verb==='prune') {
            $row=$db->row('SELECT id,version,0 AS epoch,
                floor(extract(epoch FROM clock_timestamp()-created_at))::bigint AS age,
                purged_at IS NOT NULL AS purged,retention_until<=clock_timestamp() AS expired,
                '.$db->table('platform_payload_unresolved').'(:tenant_check,:payload_check) AS unresolved
                FROM '.$db->table('payload_refs').' WHERE tenant_id=:tenant AND id=:id AND session_id=:session AND purpose=:purpose',
                $keys+['tenant_check'=>$c->id->tenant->value,'payload_check'=>$c->target,'purpose'=>$c->context->purpose]);
            if ($row===null) { throw new ContractViolation(ErrorCode::AccessDenied); }
            $held=$db->row('SELECT id FROM '.$db->table('payload_holds').' WHERE tenant_id=:tenant AND payload_id=:id AND released_at IS NULL LIMIT 1',
                ['tenant'=>$c->id->tenant->value,'id'=>$c->target])!==null;
            $reason=match(true) { $row['purged']===true=>'tombstone', $held=>'legal_hold',
                $row['unresolved']===true=>'unresolved_dependency', $row['expired']!==true=>'retention_active', default=>'expired' };
            return [$this->observation('payload',$row,$reason,$reason==='expired'?'purge':'retain')];
        }
        if ($c->verb==='relay' && $c->resource==='usage') {
            $row=$db->row('SELECT x.id,x.version,x.epoch,x.status,u.payload_hash AS request_hash,u.id AS owner_key,
                floor(extract(epoch FROM clock_timestamp()-x.created_at))::bigint AS age,
                x.lease_until>clock_timestamp() AS busy FROM '.$db->table('accounting_export').' x
                JOIN '.$db->table('accounting_usage').' u USING(tenant_id,id) WHERE x.tenant_id=:tenant AND x.id=:id',
                ['tenant'=>$c->id->tenant->value,'id'=>$c->target]);
            if ($row===null) { throw new ContractViolation(ErrorCode::AccessDenied); }
            $reason=match(true) { $row['status']==='exported'=>'exported', $row['busy']===true=>'live_claim',
                in_array($row['status'],['quarantined','manual_resolution_required'],true)=>Connection::text($row,'status'),
                default=>'usage_undelivered' };
            return [$this->observation('usage',$row,$reason,$reason==='usage_undelivered'?'relay_same_usage':'retain')];
        }
        if ($c->verb==='relay') {
            $row=$db->row('SELECT d.id,c.version,c.fencing_epoch AS epoch,c.state AS status,
                floor(extract(epoch FROM clock_timestamp()-d.created_at))::bigint AS age,
                d.payload_hash AS request_hash,d.delivery_key AS owner_key,c.cancelled,
                c.lease_until>clock_timestamp() AS busy,c.attempt_count>=c.max_attempts AS exhausted
                FROM '.$db->table('delivery_intents').' d JOIN '.$db->table('delivery_controls').' c
                ON c.tenant_id=d.tenant_id AND c.id=d.id WHERE d.tenant_id=:tenant AND d.delivery_key=:id AND c.session_id=:session',$keys);
            if ($row===null) { throw new ContractViolation(ErrorCode::AccessDenied); }
            $reason=match(true) { $row['cancelled']===true=>'cancelled', $row['status']==='delivered'=>'delivered',
                $row['status']==='failed'=>'failed', $row['busy']===true=>'live_claim', $row['exhausted']===true=>'attempts_exhausted',
                $row['status']==='unknown'||$row['status']==='sending'=>'delivery_unknown', default=>'undelivered' };
            return [$this->observation('delivery',$row,$reason,in_array($reason,['undelivered','delivery_unknown'],true)?'relay_same_key':'retain')];
        }
        $row=$db->row('SELECT r.id,r.status,r.version,r.fencing_epoch AS epoch,
            floor(extract(epoch FROM clock_timestamp()-r.created_at))::bigint AS age,
            r.lease_until>clock_timestamp() AS busy FROM '.$db->table('runs').' r JOIN '.$db->table('turns').' t
            ON t.tenant_id=r.tenant_id AND t.id=r.turn_id WHERE r.tenant_id=:tenant AND r.id=:id AND t.session_id=:session',$keys);
        if ($row===null) { throw new ContractViolation(ErrorCode::AccessDenied); }
        $reason=match(true) { in_array($row['status'],['completed','failed','cancelled'],true)=>Connection::text($row,'status'),
            $row['busy']===true=>'live_claim', $row['status']==='accepted'=>'committed_unstarted',
            $row['status']==='waiting_confirmation'=>'awaiting_confirmation', $row['status']==='waiting_action'=>'awaiting_action',
            $row['status']==='waiting_external'=>'awaiting_external', $row['status']==='cancelling'=>'cancellation_pending', default=>'stale_claim' };
        $items=[$this->observation('run',$row,$reason,in_array($reason,['committed_unstarted','stale_claim'],true)?'wake_same_run':'retain')];
        // Each source is bounded; the returned page uses a stable kind:key cursor across all sources.
        $parameters=['tenant'=>$c->id->tenant->value,'run'=>$c->target];
        $tables=[
            'invocation'=>'SELECT i.id,i.version,0 AS epoch,i.created_at,i.request_hash,i.invocation_key AS owner_key,
                coalesce(x.outcome,\'unknown\') AS status FROM '.$db->table('invocation_intents').' i
                LEFT JOIN '.$db->table('invocation_results').' x ON x.tenant_id=i.tenant_id AND x.invocation_id=i.id
                WHERE i.tenant_id=:tenant AND i.run_id=:run',
            'effect'=>'SELECT e.id,e.version,coalesce(e.claim_epoch,0) AS epoch,e.created_at,e.request_hash,
                e.owner_operation_key AS owner_key,e.outcome AS status,a.deadline_at FROM '.$db->table('effect_intents').' e
                LEFT JOIN '.$db->table('action_submissions').' a ON a.tenant_id=e.tenant_id AND a.id=e.action_ref
                WHERE e.tenant_id=:tenant AND (EXISTS(SELECT 1 FROM '.$db->table('proposal_map').' p
                WHERE p.tenant_id=e.tenant_id AND p.action_ref=e.action_ref AND p.run_id=:run)
                OR EXISTS(SELECT 1 FROM '.$db->table('run_steps').' s
                WHERE s.tenant_id=e.tenant_id AND s.run_id=:linked_run AND s.action_ref=e.action_ref))',
            'proposal'=>'SELECT p.id,p.version,0 AS epoch,p.created_at,p.payload_hash AS request_hash,
                p.action_ref AS owner_key,CASE WHEN a.id IS NULL THEN \'missing\' ELSE \'submitted\' END AS status
                FROM '.$db->table('proposal_map').' p LEFT JOIN '.$db->table('action_current').' a
                ON a.tenant_id=p.tenant_id AND a.id=p.action_ref WHERE p.tenant_id=:tenant AND (p.run_id=:run OR EXISTS(SELECT 1 FROM '.$db->table('run_steps').' s
                WHERE s.tenant_id=p.tenant_id AND s.run_id=:linked_run AND s.action_ref=p.action_ref))',
        ];
        foreach ($tables as $kind=>$query) {
            if ($c->after!==null && $kind<explode(':',$c->after,2)[0]) { continue; }
            $statement=$db->execute('SELECT q.*,floor(extract(epoch FROM clock_timestamp()-q.created_at))::bigint AS age FROM ('.$query.') q
                WHERE q.id>:after ORDER BY q.id LIMIT '.($c->limit+1),$parameters+($kind!=='invocation'?['linked_run'=>$c->target]:[])+['after'=>$this->sourceCursor($c,$kind)]);
            while (($raw=$statement->fetch(\PDO::FETCH_ASSOC))!==false) {
$r=$raw;
                $state=Connection::text($r,'status');
                $unknown=in_array($state,['unknown','pending'],true);
                $reason=$kind==='proposal'?($state==='missing'?'missing_action_submit':'action_submitted'):($unknown?$kind.'_unknown':$state);
                $route=match(true) { $kind==='proposal'&&$state==='missing'=>'submit_saved_proposal',
                    $unknown&&$kind==='effect'=>'owner_lookup_or_manual', $unknown=>'provider_lookup_or_explicit_new_invocation', default=>'retain' };
                $items[]=$this->observation($kind,$r,$reason,$route);
            }
        }
        usort($items,static fn(Observation $a,Observation $b): int=>strcmp($a->kind.':'.$a->key,$b->kind.':'.$b->key));
        return array_values(array_filter($items,static fn(Observation $o): bool=>$o->kind.':'.$o->key>($c->after??'')));
    }
    public function hasUnknown(Command $c): bool
    {
        $this->environment->authorize($c->context); $this->authority->authorize($c);
        $db=$this->environment->db;
        $row=$db->row('SELECT EXISTS(SELECT 1 FROM '.$db->table('invocation_intents').' i
            LEFT JOIN '.$db->table('invocation_results').' x ON x.tenant_id=i.tenant_id AND x.invocation_id=i.id
            WHERE i.tenant_id=:tenant_i AND i.run_id=:run_i AND (x.outcome IS NULL OR x.outcome=\'unknown\'))
            OR EXISTS(SELECT 1 FROM '.$db->table('effect_intents').' e WHERE e.tenant_id=:tenant_e AND
            (EXISTS(SELECT 1 FROM '.$db->table('proposal_map').' p WHERE p.tenant_id=e.tenant_id AND p.action_ref=e.action_ref AND p.run_id=:run_e)
            OR EXISTS(SELECT 1 FROM '.$db->table('run_steps').' s
            WHERE s.tenant_id=e.tenant_id AND s.run_id=:linked AND s.action_ref=e.action_ref)) AND e.outcome IN (\'unknown\',\'pending\')) AS unresolved',
            ['tenant_i'=>$c->id->tenant->value,'run_i'=>$c->target,'tenant_e'=>$c->id->tenant->value,'run_e'=>$c->target,'linked'=>$c->target]);
        return ($row['unresolved']??true)!==false;
    }
    private function sourceCursor(Command $c,string $kind): string
    {
        if ($c->after===null) { return ''; }
        [$cursorKind,$cursorKey]=array_pad(explode(':',$c->after,2),2,'');
        return $kind===$cursorKind?$cursorKey:'';
    }
    /** @param array<string,mixed> $r */
    private function observation(string $kind,array $r,string $reason,string $route): Observation
    {
        return new Observation($kind,Connection::text($r,'id'),is_string($r['status']??null)?$r['status']:'metadata',
            $reason,$route,Connection::number($r,'version'),Connection::number($r,'epoch'),max(0,Connection::number($r,'age')),
            isset($r['request_hash'])?Connection::text($r,'request_hash'):null,isset($r['owner_key'])?Connection::text($r,'owner_key'):null,
            isset($r['deadline_at'])?Connection::text($r,'deadline_at'):null,
            str_contains($reason,'unknown')?($kind==='effect'?'owner_lookup_or_manual':'operator_policy_required'):null);
    }
}
