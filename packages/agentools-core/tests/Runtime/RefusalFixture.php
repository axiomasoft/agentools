<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Tests\Runtime;

require_once dirname(__DIR__) . '/Security/bootstrap.php';

use AgenToolsPlatform\Core\Actions\ActionView;
use AgenToolsPlatform\Core\Budget\{Admission, AdmissionRequest, BudgetPort};
use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, Ref, RunId, TurnId, UtcInstant};
use AgenToolsPlatform\Core\Models\{Deployment, ModelRequest, ModelResponse, ModelTransport};
use AgenToolsPlatform\Core\Runtime\{AdvanceLease, AdvanceRun, ContinuationPolicy, InvocationCheckpoint, InvocationGuard, InvocationJournal, InvocationPayloads, InvocationPlan, InvocationRefusal, ProposalDispatcher, SavedProposal};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Tests\Security\Fixture as SecurityFixture;

/** Test-only journal proxy: persisted state survives a new AdvanceRun instance. */
final class RefusalFixture
{
    public SecurityFixture $security;
    public InvocationPlan $plan;
    public AdvanceLease $lease;
    public RefusalJournal $journal;
    public RefusalProvider $provider;
    public InvocationPayloads $payloads;
    public BudgetPort $budget;
    public InvocationGuard $guard;
    public ProposalDispatcher $actions;

    public function __construct()
    {
        $this->security = $f = new SecurityFixture();
        $this->plan = new InvocationPlan(new RunId($f->core->tenant, 'run:refusal'), new TurnId($f->core->tenant, 'turn:refusal'),
            'model', $f->request, $f->core->ref('request:refusal'), $f->deployment, $f->compiled->snapshot,
            $f->core->bundle, $f->core->ref('business'), $f->core->ref('destination'), $f->definitions,
            new ContinuationPolicy(), 1, null, null, $f->core->hasher);
        $this->lease = new AdvanceLease($this->plan->runId, 1, 1, 'test-owner');
        $this->journal = new RefusalJournal();
        $this->provider = new RefusalProvider($f);
        $this->payloads = new class implements InvocationPayloads {
            /** @var array<string,string> */ public array $saved = [];
            public function put(ExecutionContext $context, Ref $key, \AgenToolsPlatform\Core\Common\PayloadHash $hash, string $json): void
            { $this->saved[$key->value] = $json; }
            public function find(ExecutionContext $context, Ref $key): ?string { return $this->saved[$key->value] ?? null; }
            public function get(ExecutionContext $context, Ref $key): string { return $this->saved[$key->value]; }
        };
        $this->budget = new class implements BudgetPort {
            public function status(AdmissionRequest $request): Admission { return new Admission('not_found'); }
            public function admit(AdmissionRequest $request): Admission
            { return new Admission('admitted', new Ref($request->operationKey->tenant, 'grant'), expiresAt: new UtcInstant('2026-12-01T00:00:00Z'), ownerRevision: 'v1'); }
            public function settle(Ref $reservation, Ref $usageEvent): void { throw new \LogicException('No settlement expected'); }
            public function release(Ref $reservation, string $provenNotSentReason): void { throw new \LogicException('No release expected'); }
            public function renew(Ref $reservation, UtcInstant $deadline): Admission { throw new \LogicException('No renewal expected'); }
        };
        $this->guard = new class($f) implements InvocationGuard {
            public function __construct(private SecurityFixture $f) {}
            public function now(): UtcInstant { return $this->f->core->now; }
            public function assertCurrent(ExecutionContext $context, InvocationPlan $plan): void {}
        };
        $this->actions = new class implements ProposalDispatcher {
            public function status(ExecutionContext $context, SavedProposal $proposal): ActionView { throw new \LogicException('No actions expected'); }
            public function submit(ExecutionContext $context, SavedProposal $proposal): ActionView { throw new \LogicException('No dispatch expected'); }
        };
    }

    public function runtime(): AdvanceRun
    {
        $f = $this->security;
        $transport = new \AgenToolsPlatform\Core\Runtime\GuardedTransport($this->provider, $f->core->context, $f->snapshot,
            \AgenToolsPlatform\Core\Security\EntryPoint::Text, $f->deployment, $f->core->ref('credential:opaque'), $f->definitions,
            $f->guard, $f->api, $f->core->hasher);
        return new AdvanceRun($this->journal, $transport, $this->budget, $this->guard, $this->payloads, $this->actions, $f->core->hasher);
    }
    public function advance(): InvocationCheckpoint
    { return $this->runtime()->advance($this->security->core->context, $this->lease, $this->plan); }
}

final class RefusalProvider implements ModelTransport
{
    public int $calls = 0;
    public ?\Throwable $failure = null;
    public function __construct(private SecurityFixture $f) {}
    public function generate(ModelRequest $request, Deployment $deployment): ModelResponse
    {
        ++$this->calls;
        if ($this->failure !== null) { throw $this->failure; }
        return $this->f->transport->generate($request, $deployment);
    }
    public function stream(ModelRequest $request, Deployment $deployment): iterable
    { throw new \LogicException('No stream expected'); }
}

final class RefusalJournal implements InvocationJournal
{
    public ?InvocationCheckpoint $saved = null;
    public int $outbounds = 0;
    public ?\Closure $afterOutbound = null;
    public ?\Closure $beforeRefusal = null;
    public function open(ExecutionContext $context, AdvanceLease $lease, InvocationPlan $plan, AdmissionRequest $admission): InvocationCheckpoint
    { return $this->saved ??= new InvocationCheckpoint($lease, ['stage' => 'admission_pending']); }
    public function admitted(ExecutionContext $context, InvocationPlan $plan, InvocationCheckpoint $saved, Admission $admission): InvocationCheckpoint
    { return $this->saved = new InvocationCheckpoint($saved->lease, ['stage' => 'admitted', 'admission' => $admission->toArray()]); }
    public function outbound(ExecutionContext $context, InvocationPlan $plan, InvocationCheckpoint $saved): InvocationCheckpoint
    {
        ++$this->outbounds;
        $this->saved = new InvocationCheckpoint($saved->lease, ['stage' => 'outbound', 'possible_cost' => true, 'request_count' => null]);
        if ($this->afterOutbound !== null) { ($this->afterOutbound)(); }
        return $this->saved;
    }
    public function result(ExecutionContext $context, InvocationPlan $plan, InvocationCheckpoint $saved, Ref $payload, \AgenToolsPlatform\Core\Common\PayloadHash $hash, \AgenToolsPlatform\Core\Runtime\ExchangeResult $result, ?Ref $resolutionEvidence = null): InvocationCheckpoint
    { return $this->saved = new InvocationCheckpoint($saved->lease, ['stage' => $result->accepted() ? 'response' : 'unknown', 'possible_cost' => true,
        'request_count' => $result->requestCount, 'result_ref' => $payload->value, 'result_hash' => $hash->value]); }
    public function refused(ExecutionContext $context, InvocationPlan $plan, InvocationCheckpoint $saved, InvocationRefusal $refusal): InvocationCheckpoint
    {
        if ($this->beforeRefusal !== null) { ($this->beforeRefusal)(); }
        if (!in_array($saved->stage(), ['admitted', 'outbound'], true)) { throw new ContractViolation(ErrorCode::PolicyConflict); }
        return $this->saved = new InvocationCheckpoint($saved->lease, ['stage' => 'refused', 'refusal' => $refusal->toArray(), 'request_count' => 0, 'possible_cost' => false]);
    }
    public function submitted(ExecutionContext $context, InvocationPlan $plan, InvocationCheckpoint $saved, SavedProposal $proposal): InvocationCheckpoint
    { throw new \LogicException('No proposals expected'); }
    public function actionsComplete(ExecutionContext $context, InvocationPlan $plan, InvocationCheckpoint $saved): InvocationCheckpoint
    { throw new \LogicException('No actions expected'); }
    public function finish(ExecutionContext $context, InvocationPlan $plan, InvocationCheckpoint $saved): InvocationCheckpoint
    { return $this->saved = new InvocationCheckpoint($saved->lease, $saved->data + ['finished' => true]); }
}
