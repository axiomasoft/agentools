<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Runtime;

use AgenToolsPlatform\Core\Budget\{AdmissionRequest, BudgetPort};
use AgenToolsPlatform\Core\Common\{CanonicalHasher, ContractViolation, ErrorCode, PayloadHash, Ref, UtcInstant};
use AgenToolsPlatform\Core\Models\{ModelTransport, Time};
use AgenToolsPlatform\Core\Security\ExecutionContext;

/** Bounded one-exchange advance. Actions alone own effects; every retry reads the journal first. */
final readonly class AdvanceRun
{
    public function __construct(private InvocationJournal $journal, private ModelTransport $transport,
        private BudgetPort $budget, private InvocationGuard $guard, private InvocationPayloads $payloads,
        private ProposalDispatcher $actions, private CanonicalHasher $hasher, private ?ProviderLookup $lookup = null) {}

    public function advance(ExecutionContext $context, AdvanceLease $lease, InvocationPlan $plan): InvocationCheckpoint
    {
        $this->check($context, $plan);
        $admissionRequest = new \AgenToolsPlatform\Core\Budget\AdmissionRequest(new \AgenToolsPlatform\Core\Common\Ref($plan->runId->tenant, 'admit:' . $plan->request->invocationId->value),
            ['tenant_ref' => $context->session->tenant->value, 'actor_ref' => $context->identity->actorRef->toArray(),
                'agent_principal_ref' => $context->identity->agentPrincipalRef?->toArray(), 'plan' => $plan->identity(), 'context_scope' => $context->scopeHash($this->hasher)->value,
                'subject' => $context->identity->subjectRef?->toArray(), 'purpose' => $context->purpose], $this->hasher);
        $saved = $this->journal->open($context, $lease, $plan, $admissionRequest);
        if ($saved->stage() === 'refused') { throw InvocationRefusal::restore($saved)->exception(); }
        if ($saved->stage() === 'admission_pending') {
            // status handles owner-accepted/local-response-lost without a second reservation.
            $admission = $this->budget->status($admissionRequest);
            if ($admission->status === 'not_found') {
                $admission = $this->budget->admit($admissionRequest);
            }
            $saved = $this->journal->admitted($context, $plan, $saved, $admission);
            if ($saved->stage() !== 'admitted') { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::AdmissionDenied); }
        }
        if ($saved->stage() === 'admitted') {
            $this->check($context, $plan);
            $admitted = $saved->data['admission'] ?? null;
            $expires = is_array($admitted) ? ($admitted['expires_at'] ?? null) : null;
            if (!is_string($expires) || Time::compare($this->guard->now(), new \AgenToolsPlatform\Core\Common\UtcInstant($expires)) >= 0) {
                throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::AdmissionDenied);
            }
            // The guarded adapter can prove a local refusal; arbitrary transport exceptions cannot.
            if ($this->transport instanceof GuardedTransport) {
                $result = $this->transport->exchange($plan, $this->journal, $context, $saved);
                if ($result instanceof InvocationRefusal) {
                    $saved = $this->journal->refused($context, $plan, $saved, $result);
                    throw InvocationRefusal::restore($saved)->exception();
                }
            } else {
                $saved = $this->journal->outbound($context, $plan, $saved);
                // No catch surrounds commit/result persistence: persistence failure must not invent a failure/zero.
                try {
                    $response = $this->transport->generate($plan->request, $plan->deployment);
                    $result = ExchangeResult::response($plan, $response, $this->hasher);
                } catch (\Throwable) {
                    $result = ExchangeResult::unknown($plan, $this->hasher);
                }
            }
            $saved = $this->persist($context, $plan, $saved, $result);
        } elseif (in_array($saved->stage(), ['outbound', 'unknown'], true)) {
            // An intent may already have reached the provider. Never resend this Invocation.
            $this->check($context, $plan);
            $durable = $this->payloads->find($context, new \AgenToolsPlatform\Core\Common\Ref($plan->runId->tenant,
                'exchange:' . $plan->request->invocationId->value . ':accepted'));
            if ($durable !== null) {
                $result = ExchangeResult::decode($plan, $durable, $this->hasher);
                if (!$result->accepted()) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::IntegrityConflict); }
                $saved = $this->persist($context, $plan, $saved, $result, new \AgenToolsPlatform\Core\Common\Ref($plan->runId->tenant,
                    'exchange:' . $plan->request->invocationId->value . ':accepted'));
            }
            $response = $saved->stage() === 'response' ? null : $this->lookup?->find($context, $plan);
            if ($response !== null) {
                $plan->requestHash->assertSame($response->requestHash);
                $result = ExchangeResult::response($plan, $response->response, $this->hasher);
                if ($result->accepted()) { $saved = $this->persist($context, $plan, $saved, $result, $response->evidence); }
            }
            if ($saved->stage() === 'outbound') { $saved = $this->persist($context, $plan, $saved, ExchangeResult::unknown($plan, $this->hasher)); }
        }
        if (!in_array($saved->stage(), ['response', 'waiting_action'], true)) { return $saved; }
        $payload = $saved->data['result_ref'] ?? null;
        $hash = $saved->data['result_hash'] ?? null;
        if (!is_string($payload) || !is_string($hash)) { throw new \UnexpectedValueException('Missing saved response'); }
        $json = $this->payloads->get($context, new \AgenToolsPlatform\Core\Common\Ref($plan->runId->tenant, $payload));
        (new \AgenToolsPlatform\Core\Common\PayloadHash($hash))->assertSame(new \AgenToolsPlatform\Core\Common\PayloadHash('sha-256:' . hash('sha256', $json)));
        $result = ExchangeResult::decode($plan, $json, $this->hasher);
        if ($saved->stage() === 'waiting_action') {
            foreach ($result->proposals as $proposal) {
                $this->check($context, $plan);
                $view = $this->actions->status($context, $proposal);
                $proposal->actionId->assertSame($view->receipt->actionId);
                if ($view->receipt->wireStatus !== 'completed') { return $saved; }
            }
            return $this->journal->actionsComplete($context, $plan, $saved);
        }
        foreach ($result->proposals as $proposal) {
            $submitted = $saved->data['submitted'] ?? [];
            if (!is_array($submitted)) { throw new \UnexpectedValueException('Invalid saved dispatch'); }
            if (in_array($proposal->id->value, $submitted, true)) { continue; }
            $this->check($context, $plan);
            $view = $this->actions->submit($context, $proposal);
            $proposal->actionId->assertSame($view->receipt->actionId);
            if ($proposal->definition->toArray() !== $view->definition->toArray()) {
                throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::IntegrityConflict);
            }
            $saved = $this->journal->submitted($context, $plan, $saved, $proposal);
        }
        return $this->journal->finish($context, $plan, $saved);
    }

    private function persist(ExecutionContext $context, InvocationPlan $plan, InvocationCheckpoint $saved, ExchangeResult $result, ?Ref $resolutionEvidence = null): InvocationCheckpoint
    {
        $key = new \AgenToolsPlatform\Core\Common\Ref($plan->runId->tenant, 'exchange:' . $plan->request->invocationId->value . ':' . ($result->accepted() ? 'accepted' : 'unknown'));
        $this->payloads->put($context, $key, $result->hash(), $result->json);
        return $this->journal->result($context, $plan, $saved, $key, $result->hash(), $result, $resolutionEvidence);
    }

    private function check(ExecutionContext $context, InvocationPlan $plan): void
    {
        $this->guard->assertCurrent($context, $plan);
        \AgenToolsPlatform\Core\Models\TransportPreflight::check($plan->request, $plan->deployment, $this->guard->now());
        if ($plan->request->cancellation?->isCancellationRequested()) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::Cancelled); }
        if (Time::compare($this->guard->now(), $plan->request->deadline) >= 0) {
            throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::DeadlineExceeded);
        }
    }
}
