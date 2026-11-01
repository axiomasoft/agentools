<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\ReadJourney;

use AgenToolsPlatform\Core\Actions\ActionHandler;
use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, PayloadHash, Ref};
use AgenToolsPlatform\Core\Runtime\SavedProposal;
use AgenToolsPlatform\Postgres\Runtime\Connection;

/** Host business source of truth. Read is private disclosure with bounded cost, not harmless IO. */
final readonly class ReadOwner implements ActionHandler
{
    public function __construct(private SupportJourney $journey) {}
    public function handlerId(): string { return 'support.ticket'; }
    private function authorize(): void
    {
        $j=$this->journey;$j->idle();$j->context->assertCurrent($j->host);
        $row=$j->db->row('SELECT p.disclosure_allowed,p.emergency,t.visible,t.subject_ref FROM '.$j->db->table('demo_permissions').' p JOIN '.$j->db->table('demo_tickets').' t ON t.tenant_id=p.tenant_id
            WHERE p.tenant_id=:tenant AND t.id=\'ST-17\'',['tenant'=>$j->tenant->value]);
        if($row!==null && $row['emergency']===true){throw new ContractViolation(ErrorCode::DeploymentUnavailable);}
        if($j->guard->isCancellationRequested()){throw new ContractViolation(ErrorCode::Cancelled);}
        if($row===null || $row['disclosure_allowed']!==true || $row['visible']!==true || $row['subject_ref']!==$j->context->identity->subjectRef?->value){throw new ContractViolation(ErrorCode::AccessDenied);}
    }
    public function read(SavedProposal $proposal): Ref
    {
        $j=$this->journey;$this->authorize();
        if(\AgenToolsPlatform\Core\Models\Time::compare($j->guard->now(),$proposal->deadline)>=0){throw new ContractViolation(ErrorCode::DeadlineExceeded);}
        $j->boundary('owner');$this->authorize();
        $row=$j->db->row('SELECT payload_ref FROM '.$j->db->table('demo_reads').' WHERE tenant_id=:tenant AND action_id=:action',
            ['tenant'=>$j->tenant->value,'action'=>$proposal->actionId->value]);
        if($row!==null){return $j->ref(Connection::text($row,'payload_ref'));}
        $ticket=$j->db->row('SELECT status FROM '.$j->db->table('demo_tickets').' WHERE tenant_id=:tenant AND id=\'ST-17\'', ['tenant'=>$j->tenant->value]);
        if($ticket===null || !in_array($ticket['status'],['open','closed'],true)){throw new ContractViolation(ErrorCode::CapabilityUnavailable);}
        $result=['ticket_ref'=>'ST-17','status'=>$ticket['status'],'model_view'=>'Ticket ST-17 status: '.$ticket['status'].'.',
            'user_view'=>'Your ticket ST-17 is '.$ticket['status'].'.','raw_result_ref'=>'host-ticket:ST-17','audit_ref'=>'host-audit:'.$proposal->actionId->value,
            'evidence_ref'=>'ticket-revision:1','source_version'=>'1','classification'=>'private','cost_bound_minor'=>1];
        $json=(new Jcs())->canonicalize($result);$ref=$j->ref('owner-result:'.$proposal->actionId->value);
        $j->vault->put($j->context,$ref,new PayloadHash('sha-256:'.hash('sha256',$json)),$json);
        $this->authorize();$j->db->execute('INSERT INTO '.$j->db->table('demo_reads').'(tenant_id,action_id,payload_ref) VALUES (:tenant,:action,:payload) ON CONFLICT DO NOTHING',
            ['tenant'=>$j->tenant->value,'action'=>$proposal->actionId->value,'payload'=>$ref->value]);$j->boundary('read-result');return $ref;
    }
    /** @return array{model_view: string, user_view: string} */
    public function saved(): array
    {
        $this->authorize();$j=$this->journey;
        $row=$j->db->row('SELECT payload_ref FROM '.$j->db->table('demo_reads').' WHERE tenant_id=:tenant',['tenant'=>$j->tenant->value]);
        if($row===null){throw new ContractViolation(ErrorCode::AccessDenied);}
        $data=json_decode($j->vault->get($j->context,$j->ref(Connection::text($row,'payload_ref'))),true,32,JSON_THROW_ON_ERROR);
        if(!is_array($data) || !is_string($data['model_view']??null) || !is_string($data['user_view']??null)){throw new \RuntimeException('Invalid read result');}
        $j->boundary('owner-disclosure');$this->authorize();
        return ['model_view'=>$data['model_view'],'user_view'=>$data['user_view']];
    }
}
