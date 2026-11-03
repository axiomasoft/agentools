<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Postgres\Usage;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, UtcInstant};
use AgenToolsPlatform\Core\Runtime\{ExchangeResult, InvocationPlan};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Core\Usage\{Producer, ProviderMapping, UsageNormalizer};
use AgenToolsPlatform\Postgres\Runtime\Connection;

/** Participant in the native invocation transaction, never a second SDK producer. */
final readonly class Recorder
{
    public function __construct(public Repository $repository, private CanonicalHasher $hasher, private ProviderMapping $mapping) {}

    public function outboundInTransaction(InvocationPlan $plan): void
    {
        $r=$this->repository; $r->tenant->assertSame($plan->runId->tenant); $r->lockTenant();
        $row=$r->db->row('SELECT status,reservation_ref FROM '.$r->db->table('accounting_reservation').'
            WHERE tenant_id=:tenant AND id=:id FOR UPDATE', ['tenant'=>$r->tenant->value,'id'=>'admit:'.$plan->request->invocationId->value]);
        if ($row===null || $row['status']!=='active' || $row['reservation_ref']===null) { throw new AccountingPending(); }
        $r->db->execute('UPDATE '.$r->db->table('accounting_reservation').' SET exposure=\'unknown\',not_sent_evidence=NULL,
            version=version+1,updated_at=clock_timestamp() WHERE tenant_id=:tenant AND id=:id',
            ['tenant'=>$r->tenant->value,'id'=>'admit:'.$plan->request->invocationId->value]);
    }

    public function recordInTransaction(ExecutionContext $context, InvocationPlan $plan, ExchangeResult $result, string $usageId, int $version): bool
    {
        $r=$this->repository; $r->tenant->assertSame($plan->runId->tenant); $r->tenant->assertSame($context->session->tenant);
        $prior=$r->db->row('SELECT id FROM '.$r->db->table('accounting_usage').' WHERE tenant_id=:tenant AND invocation_id=:invocation
            ORDER BY usage_version DESC LIMIT 1',['tenant'=>$r->tenant->value,'invocation'=>$plan->request->invocationId->value]);
        $intent=$r->db->row('SELECT to_char(created_at AT TIME ZONE \'UTC\',\'YYYY-MM-DD"T"HH24:MI:SS.US"Z"\') AS occurred
            FROM '.$r->db->table('invocation_intents').' WHERE tenant_id=:tenant AND id=:id',
            ['tenant'=>$r->tenant->value,'id'=>$plan->request->invocationId->value]) ?? throw new AccountingPending();
        $certainty=$result->accepted()?'final':(($result->usage->inputTokens!==null || $result->usage->outputTokens!==null
            || $result->usage->cacheReadTokens!==null || $result->usage->cacheWriteTokens!==null)?'partial':'unknown');
        $metadata=['schema_version'=>'1','event_id'=>$usageId,'tenant_ref'=>$r->tenant->value,
            'actor_ref'=>$context->identity->actorRef->value,'subject_ref'=>$context->identity->subjectRef?->value,
            'assistant_session_ref'=>$context->session->value,'turn_ref'=>$plan->turnId->value,'run_ref'=>$plan->runId->value,
            'business_operation_ref'=>$plan->businessOperation->value,'deployment_ref'=>$plan->deployment->ref->value,
            'model_revision'=>null,'correlation_id'=>$plan->request->invocationId->value,'certainty'=>$certainty,
            'occurred_at'=>(new UtcInstant(Connection::text($intent,'occurred')))->value,
            'estimate'=>null,'pricing_version'=>null,'supersedes_event_id'=>$prior===null?null:Connection::text($prior,'id')];
        $event=(new UsageNormalizer($this->hasher))->observe($metadata,$plan->request->invocationId->value,$version,
            Producer::Transport,Producer::Transport,$this->mapping,['inputTokens'=>$result->usage->inputTokens,'outputTokens'=>$result->usage->outputTokens,
                'cacheReadTokens'=>$result->usage->cacheReadTokens,'cacheWriteTokens'=>$result->usage->cacheWriteTokens],$result->requestCount===1?true:null)->event
            ?? throw new AccountingPending();
        $ok=$r->appendInTransaction($event);
        $r->db->execute('UPDATE '.$r->db->table('accounting_reservation').' SET exposure=:exposure,status=:status,not_sent_evidence=NULL,
            version=version+1,updated_at=clock_timestamp() WHERE tenant_id=:tenant AND id=:id',
            ['exposure'=>$ok && $certainty==='final'?'known':'unknown','status'=>$ok && $certainty==='final'?'active':'unknown',
                'tenant'=>$r->tenant->value,'id'=>'admit:'.$plan->request->invocationId->value]);
        return $ok;
    }
}
