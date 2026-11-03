<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Laravel\Observability;

use AgenToolsPlatform\Core\Common\{ContractViolation,ErrorCode,Ref,RunId};
use AgenToolsPlatform\Core\Security\{ExecutionContext,TraceLinks};
use AgenToolsPlatform\Postgres\Runtime\{Connection,RepositoryContext};

/** Bounded technical links only; never reads invocation state_ref, checkpoints, payloads or secrets. */
final readonly class TraceGraph
{
    public function __construct(private RepositoryContext $environment) {}
    /** @return list<TraceLinks> */
    public function read(ExecutionContext $context,RunId $run,int $limit=50,?string $after=null): array
    {
        if ($limit<1 || $limit>100 || strlen($after??'')>256) { throw new \InvalidArgumentException('Bounded trace page required'); }
        $context->session->tenant->assertSame($run->tenant);$this->environment->authorize($context);
        $db=$this->environment->db;
        $root=$db->row('SELECT r.turn_id FROM '.$db->table('runs').' r JOIN '.$db->table('turns').' t
            ON t.tenant_id=r.tenant_id AND t.id=r.turn_id WHERE r.tenant_id=:tenant AND r.id=:id AND t.session_id=:session',
            ['tenant'=>$run->tenant->value,'id'=>$run->value,'session'=>$context->session->value]);
        if ($root===null) { throw new ContractViolation(ErrorCode::AccessDenied); }
        $rows=$db->execute('SELECT kind,id FROM (
            SELECT \'invocation\' AS kind,i.id FROM '.$db->table('invocation_intents').' i WHERE i.tenant_id=:ti AND i.run_id=:ri
            UNION SELECT \'action\',s.action_ref FROM '.$db->table('run_steps').' s WHERE s.tenant_id=:ta AND s.run_id=:ra AND s.action_ref IS NOT NULL
            UNION SELECT \'action\',p.action_ref FROM '.$db->table('proposal_map').' p WHERE p.tenant_id=:tp AND p.run_id=:rp
            UNION SELECT \'effect\',e.id FROM '.$db->table('effect_intents').' e WHERE e.tenant_id=:te AND (
                EXISTS(SELECT 1 FROM '.$db->table('run_steps').' s WHERE s.tenant_id=e.tenant_id AND s.run_id=:re AND s.action_ref=e.action_ref)
                OR EXISTS(SELECT 1 FROM '.$db->table('proposal_map').' p WHERE p.tenant_id=e.tenant_id AND p.run_id=:rep AND p.action_ref=e.action_ref))
            UNION SELECT \'delivery\',d.id FROM '.$db->table('delivery_intents').' d WHERE d.tenant_id=:td AND d.run_id=:rd
            ) nodes WHERE kind || \':\' || id>:after ORDER BY kind,id LIMIT '.$limit,
            ['ti'=>$run->tenant->value,'ri'=>$run->value,'ta'=>$run->tenant->value,'ra'=>$run->value,'tp'=>$run->tenant->value,'rp'=>$run->value,
                'te'=>$run->tenant->value,'re'=>$run->value,'rep'=>$run->value,'td'=>$run->tenant->value,'rd'=>$run->value,'after'=>$after??'']);
        $out=[];
        while (($row=$rows->fetch(\PDO::FETCH_ASSOC))!==false) {
            $kind=Connection::text($row,'kind');$id=new Ref($run->tenant,Connection::text($row,'id'));
            $out[]=new TraceLinks(turn:new Ref($run->tenant,Connection::text($root,'turn_id')),run:new Ref($run->tenant,$run->value),
                invocation:$kind==='invocation'?$id:null,action:$kind==='action'?$id:null,effect:$kind==='effect'?$id:null,delivery:$kind==='delivery'?$id:null);
        }
        return $out;
    }
}
