<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Managed;

use AgenToolsPlatform\Core\Actions\{ActionApplication, ActionView};
use AgenToolsPlatform\Core\Actions\Confirmation\Preview;
use AgenToolsPlatform\Core\Budget\AdmissionRequest;
use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, PayloadHash, Ref, UtcInstant};
use AgenToolsPlatform\Core\Models\Time;
use AgenToolsPlatform\Core\Runtime\{AdvanceRun, InvocationCheckpoint, InvocationGuard, InvocationPlan, ProviderLookup};
use AgenToolsPlatform\Core\Security\{ExecutionContext, TrustedInput, TraceLinks};
use AgenToolsPlatform\Core\Observability\{Stage,Outcome};
use AgenToolsPlatform\Postgres\Actions\SubmissionLifecycle;
use AgenToolsPlatform\Postgres\Confirmation\{ScopedReplayStore, Service};
use AgenToolsPlatform\Postgres\Models\InvocationRepository;
use AgenToolsPlatform\Postgres\Runtime\RepositoryContext;

/** Trusted construction owns persistence choices. There is exactly one effect-free Runtime advance. */
final readonly class Composition
{
    public RepositoryContext $repositories;
    public ActionApplication $actions;
    public Service $confirmation;
    private AdvanceRun $runtime;

    public function __construct(private HostBindings $ports, private Scope $scope,
        private ?\AgenToolsPlatform\Laravel\Usage\Accounting $accounting = null)
    {
        $p = $ports;
        $this->repositories = new RepositoryContext($p->connection, $p->hasher, $p->authorization, $p->revisions);
        $this->actions = new ActionApplication($p->registry, $p->policy, $p->authorization, $p->schemas,
            new SubmissionLifecycle($this->repositories, $p->registry, $p->policy, $p->schemas));
        $this->confirmation = new Service($this->repositories, $this->actions, $p->registry, $p->policy,
            $p->confirmation, $p->keys, new ScopedReplayStore($p->replayConnection, $p->schema), $p->sealer, $p->clock);
        $guard = new class($scope, $p->guard) implements InvocationGuard {
            public function __construct(private Scope $scope, private InvocationGuard $host) {}
            public function now(): UtcInstant { return $this->host->now(); }
            public function assertCurrent(ExecutionContext $context, InvocationPlan $plan): void
            {
                $this->scope->assertActive();
                $this->host->assertCurrent($context, $plan);
            }
        };
        $this->runtime = new AdvanceRun(new InvocationRepository($this->repositories, accounting: $accounting?->recorder), ($p->telemetry===null?$p->transport:new \AgenToolsPlatform\Laravel\Observability\ObservedTransport($p->transport,$p->telemetry)), $accounting === null ? $p->budget : $accounting->budget,
            $guard, $p->payloads, new DurableProposals($this->actions, $this->confirmation, $p->registry,
                $p->policy, $p->hasher, $p->json, $scope, $p->payloads), $p->hasher,
            $p->transport instanceof ProviderLookup ? $p->transport : null);
    }

    public function execute(Input $entry): DeliveryReceipt
    {
        $input=null;
        try { return $this->executeManaged($entry,$input); }
        catch (\Throwable $error) {
            if ($input!==null) { $this->ports->telemetry?->failure($input->context,$entry->entry,$error); }
            throw $error;
        }
    }

    /** @param-out TrustedInput $input */
    private function executeManaged(Input $entry,?TrustedInput &$input): DeliveryReceipt
    {
        $p = $this->ports;
        $this->scope->assertActive();
        $authentication = $p->host->authenticate($entry);
        $this->scope->assertActive();
        $input = $p->host->trusted($entry);
        $this->accounting?->tenant->assertSame($input->context->session->tenant);
        $input->context->identity->tenant->assertSame($authentication->tenant);
        $this->current($input);
        $this->decision($input, 'tenant/session', entry:$entry->entry);
        $bundle = $p->host->profileData($input);
        $input->context->identity->tenant->assertSame($bundle->ref->tenant);
        $this->decision($input, 'profile/data', entry:$entry->entry);
        $this->current($input);
        $work = $p->host->compile($input, $bundle, $entry->entry);
        if ($entry->entry === Entry::DirectAction ? $work->action === null : $work->plan === null) {
            throw new ContractViolation(ErrorCode::InvalidInput);
        }
        if ($work->plan !== null) {
            if ($work->plan->bundle->toArray() !== $bundle->toArray()) { throw new ContractViolation(ErrorCode::StaleContext); }
            $work->plan->snapshot->assertScope($input->context, $p->hasher);
        } else {
            $work->action->request->scopeHash->assertSame($input->context->scopeHash($p->hasher));
        }
        $this->decision($input, 'context', entry:$entry->entry,work:$work);
        $this->current($input);
        $p->host->deployment($input, $work);
        $this->decision($input, 'deployment', entry:$entry->entry,work:$work);
        $this->current($input);
        $result = $this->advance($input, $work);
        $this->decision($input, 'runtime', $result instanceof InvocationCheckpoint ? $result->stage()
            : ($result instanceof Preview ? 'confirmation_required' : $result->receipt->wireStatus),entry:$entry->entry,work:$work,result:$result);
        $this->current($input);
        $checked = $p->host->disclose($input, $result);
        $input->context->identity->tenant->assertSame($checked->tenant);
        $this->decision($input, 'disclosure', entry:$entry->entry,work:$work);
        $this->current($input);
        $receipt = $p->host->deliver($input, $checked, $entry->entry);
        $input->context->identity->tenant->assertSame($receipt->id->tenant);
        $this->decision($input, 'delivery', $receipt->acknowledgement === null ? 'unknown' : 'acknowledged',entry:$entry->entry,work:$work,result:$result,delivery:$receipt);
        return $receipt;
    }

    private function advance(TrustedInput $input, Work $work): InvocationCheckpoint|ActionView|Preview
    {
        if ($work->action !== null) {
            $p = $this->ports;
            $p->payloads->put($input->context, $work->action->request->inputRef,
                new PayloadHash('sha-256:'.hash('sha256', $work->action->inputJson)), $work->action->inputJson);
            $request = new AdmissionRequest(new Ref($input->context->identity->tenant,
                'admit-action:'.substr($p->hasher->hash($input->context->identity->tenant, 'action-admission-key',
                    [$work->action->request->originRef->value, $work->action->request->idempotencyKey->value])->value, 8)),
                ['action_request_hash' => $work->action->request->requestHash->value,
                    'scope_hash' => $work->action->request->scopeHash->value], $p->hasher);
            $admission = $p->budget->status($request);
            if ($admission->status === 'not_found') { $admission = $p->budget->admit($request); }
            if ($admission->status !== 'admitted' || $admission->expiresAt === null
                || Time::compare($p->guard->now(), $admission->expiresAt) >= 0) {
                throw new ContractViolation(ErrorCode::AdmissionDenied);
            }
            $this->current($input);
            $definition = $p->registry->executionView($work->action->request->definition, $p->policy->current($input->context))->definition;
            return $definition->requiresConfirmation ? $this->confirmation->prepare($work->action) : $this->actions->submit($work->action)->view;
        }
        if ($work->plan === null || $work->lease === null) { throw new ContractViolation(ErrorCode::InvalidInput); }
        return $this->runtime->advance($input->context, $work->lease, $work->plan);
    }

    private function current(TrustedInput $input): void
    {
        $this->scope->assertActive();
        $input->context->assertCurrent($this->ports->authorization);
    }

    private function decision(TrustedInput $input,string $stage,string $code='allowed',Entry $entry=Entry::Managed,
        ?Work $work=null,InvocationCheckpoint|ActionView|Preview|null $result=null,?DeliveryReceipt $delivery=null): void
    {
        $this->scope->assertActive();
        $decision=$this->ports->host->decision($input,$stage,$code);
        $tenant=$input->context->identity->tenant;$tenant->assertSame($decision->tenant);
        $telemetry=$this->ports->telemetry;
        if ($telemetry===null) { return; }
        $plan=$work?->plan;
        $action=$result instanceof ActionView?$result->receipt->actionId:($result instanceof Preview?$result->binding->action:null);
        $links=new TraceLinks(turn:$plan===null?null:new Ref($tenant,$plan->turnId->value),run:$plan===null?null:new Ref($tenant,$plan->runId->value),
            snapshot:$plan?->snapshot->ref,invocation:$plan===null?null:new Ref($tenant,$plan->request->invocationId->value),
            action:$action===null?null:new Ref($tenant,$action->value),delivery:$delivery===null?null:new Ref($tenant,$delivery->id->value),
            model:$plan?->deployment->ref,decision:$decision,hash:$plan?->requestHash);
        $where=match($stage) { 'context'=>Stage::Context,'deployment'=>Stage::Deployment,'runtime'=>Stage::Runtime,
            'disclosure'=>Stage::Disclosure,'delivery'=>Stage::Delivery,default=>Stage::Admission };
        $outcome=match($code) { 'unknown','outbound'=>Outcome::Unknown,'acknowledged','complete','completed','succeeded'=>Outcome::Succeeded,
            'confirmation_required','waiting_action','waiting_confirmation','waiting_external'=>Outcome::Waiting,
            'failed'=>Outcome::Failed,'allowed'=>Outcome::Allowed,default=>Outcome::Unknown };
        $telemetry->record($input->context,$entry,$where,$outcome,$links);
    }
}
