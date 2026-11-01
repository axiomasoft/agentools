<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Runtime;

use AgenToolsPlatform\Core\Budget\{Admission, AdmissionRequest};
use AgenToolsPlatform\Core\Common\{PayloadHash, Ref};
use AgenToolsPlatform\Core\Security\ExecutionContext;

/** Each mutation commits under current claim/version/session fencing, with no external IO in Tx. */
interface InvocationJournal
{
    public function open(ExecutionContext $context, AdvanceLease $lease, InvocationPlan $plan, AdmissionRequest $admission): InvocationCheckpoint;
    public function admitted(ExecutionContext $context, InvocationPlan $plan, InvocationCheckpoint $saved, Admission $admission): InvocationCheckpoint;
    public function outbound(ExecutionContext $context, InvocationPlan $plan, InvocationCheckpoint $saved): InvocationCheckpoint;
    /** Proven local denial from this attempt only. Never allowed from unknown/recovery; no usage or delivery. */
    public function refused(ExecutionContext $context, InvocationPlan $plan, InvocationCheckpoint $saved, InvocationRefusal $refusal): InvocationCheckpoint;
    /** Atomically result, nullable usage/outbox, normalized mapping; unknown is append-only too. */
    public function result(ExecutionContext $context, InvocationPlan $plan, InvocationCheckpoint $saved, Ref $payload, PayloadHash $hash, ExchangeResult $result, ?Ref $resolutionEvidence = null): InvocationCheckpoint;
    public function submitted(ExecutionContext $context, InvocationPlan $plan, InvocationCheckpoint $saved, SavedProposal $proposal): InvocationCheckpoint;
    public function actionsComplete(ExecutionContext $context, InvocationPlan $plan, InvocationCheckpoint $saved): InvocationCheckpoint;
    /** Atomically saved response + delivery intent; release claim. No transport delivery here. */
    public function finish(ExecutionContext $context, InvocationPlan $plan, InvocationCheckpoint $saved): InvocationCheckpoint;
}
