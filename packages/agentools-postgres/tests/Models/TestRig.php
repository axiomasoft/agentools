<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Tests\Models;

use AgenToolsPlatform\Core\Budget\AdmissionRequest;
use AgenToolsPlatform\Core\Common\{IdentityKeys, PayloadHash, Ref, TenantId, UtcInstant};
use AgenToolsPlatform\Core\Models\{Capability, CapabilityEvidence, ContentPart, Deployment, Evidence, FinishReason, GenerationOptions, JsonObject, MessageRole, ModelMessage, ModelRequest, ModelResponse, ModelUsage, Support, ToolDefinition, ToolProposal};
use AgenToolsPlatform\Core\Profiles\{BundleRef, DefinitionRef};
use AgenToolsPlatform\Core\Runtime\{AdvanceLease, AdvanceRun, ContinuationPolicy, InvocationPlan};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Claims\{ClaimPolicy, ClaimRepository};
use AgenToolsPlatform\Postgres\Inbox\IntakeRepository;
use AgenToolsPlatform\Postgres\Models\InvocationRepository;
use AgenToolsPlatform\Postgres\Runtime\{RunRepository, SessionRepository};
use AgenToolsPlatform\Postgres\Tests\Runtime\Fixture;
use PDO;

/** Explicit scripted external owners, only in tests. SQL journal is the real implementation. */
final class TestRig
{
    public Fixture $fixture;
    public ExecutionContext $context;
    public InvocationPlan $plan;
    public HostBudget $budget;
    public HostPayloads $payloads;
    public HostActions $actions;
    public ScriptedProvider $provider;
    public Guard $guard;
    public InvocationRepository $journal;
    public ?\Closure $barrier = null;

    public function __construct(public PDO $pdo, public string $schema, string $tenant = 'tenant-a', string $source = 'event')
    {
        $this->fixture = new Fixture($pdo, $schema, new TenantId($tenant));
        $this->context = $this->fixture->context();
        (new SessionRepository($this->fixture->environment))->bind($this->context, $this->fixture->binding($this->context));
        $accepted = (new IntakeRepository($this->fixture->environment))->accept($this->fixture->input($this->context, event: $source), $this->fixture->snapshot($this->context));
        $saved = (new RunRepository($this->fixture->environment))->get($this->context, $accepted->runId);
        if ($saved?->status->value === 'accepted') {
            (new RunRepository($this->fixture->environment))->beginPreparation($this->context, $this->fixture->snapshot($this->context), $accepted->runId, $saved->version);
        }
        $this->budget = new HostBudget($this); $this->payloads = new HostPayloads($this);
        $this->actions = new HostActions($this); $this->provider = new ScriptedProvider($this); $this->guard = new Guard($this);
        $this->journal = new InvocationRepository($this->fixture->environment);
        $this->plan = $this->makePlan();
    }

    public function makePlan(int $attempt = 1, ?ContinuationPolicy $policy = null, string $step = 'model', ?string $parent = null,
        ?string $reason = null, string $message = 'Read a fact', int $estimate = 10, ?UtcInstant $deadline = null): InvocationPlan
    {
        $tenant = $this->fixture->tenant;
        $accepted = (new IntakeRepository($this->fixture->environment))->accept($this->fixture->input($this->context), $this->fixture->snapshot($this->context));
        $id = (new IdentityKeys($this->fixture->hasher))->invocation($accepted->runId, $step, $attempt);
        $ref = static fn (string $id): Ref => new Ref($tenant, $id);
        $hash = new PayloadHash('sha-256:' . str_repeat('a', 64));
        $caps = [];
        foreach (Capability::cases() as $cap) { $caps[$cap->value] = new CapabilityEvidence(Support::Supported, Evidence::Documented,
            new UtcInstant('2020-01-01T00:00:00Z'), new UtcInstant('2031-01-01T00:00:00Z'), 'test-only'); }
        $deployment = new Deployment($ref('deployment'), 'scripted', 'account', 'local', 'model', $caps);
        $snapshot = $this->fixture->snapshot($this->context);
        $request = new ModelRequest($id, [new ModelMessage(MessageRole::User, [ContentPart::text($message)])],
            $snapshot->ref, $snapshot->contentHash, $ref('credential-ref'), $deadline ?? new UtcInstant('2030-01-01T00:00:00Z'),
            $this->guard, tools: [new ToolDefinition('read', 'Read', new JsonObject(['type' => 'object']))], generation: new GenerationOptions());
        return new InvocationPlan($accepted->runId, $accepted->turnId, $step, $request, $ref('request:' . $id->value), $deployment, $snapshot,
            new BundleRef($ref('bundle:v1'), 1, $hash), $ref('business-operation'), $ref('headless:reply'),
            ['read' => new DefinitionRef('read.fact', '1', $hash)], $policy ?? new ContinuationPolicy(), $estimate, $parent, $reason, $this->fixture->hasher);
    }

    public function lease(): AdvanceLease
    {
        $saved = (new RunRepository($this->fixture->environment))->get($this->context, $this->plan->runId) ?? throw new \RuntimeException('Run missing');
        $claim = (new ClaimRepository($this->fixture->environment, new ClaimPolicy(300000)))->claim($this->context, $this->plan->snapshot, $this->plan->runId, $saved->version)
            ?? throw new \RuntimeException('Claim unavailable');
        return new AdvanceLease($claim->state->id, $claim->state->version, $claim->state->fencingEpoch, $claim->owner);
    }

    public function expire(): void
    {
        $this->sql("UPDATE runs SET version=version+1,lease_until=clock_timestamp()-interval '1 microsecond' WHERE claim_owner IS NOT NULL");
    }

    public function runtime(bool $lookup = false): AdvanceRun
    {
        return new AdvanceRun($this->journal, $this->provider, $this->budget, $this->guard, $this->payloads, $this->actions,
            $this->fixture->hasher, $lookup ? $this->provider : null);
    }

    public function admission(): AdmissionRequest
    {
        return new AdmissionRequest(new Ref($this->fixture->tenant, 'admit:' . $this->plan->request->invocationId->value),
            ['tenant_ref' => $this->context->session->tenant->value, 'actor_ref' => $this->context->identity->actorRef->toArray(),
                'agent_principal_ref' => $this->context->identity->agentPrincipalRef?->toArray(), 'plan' => $this->plan->identity(), 'context_scope' => $this->context->scopeHash($this->fixture->hasher)->value,
                'subject' => $this->context->identity->subjectRef?->toArray(), 'purpose' => $this->context->purpose], $this->fixture->hasher);
    }

    public function boundary(string $name): void
    {
        $this->fixture->environment->db->assertIdle();
        if ($this->barrier !== null) { ($this->barrier)($name); }
    }

    public function response(bool $tools = true): ModelResponse
    {
        return new ModelResponse($this->plan->request->invocationId, $this->plan->deployment, $this->plan->deployment,
            'provider:' . $this->plan->request->invocationId->value, $tools ? FinishReason::ToolProposal : FinishReason::Completed,
            [ContentPart::text('A fact for ' . $this->fixture->tenant->value)],
            $tools ? [new ToolProposal($this->plan->request->invocationId, 'p1', 'read', new JsonObject(['query' => 'fact']))] : [],
            usage: $this->provider->usage ?? new ModelUsage(100, 12, 40, null));
    }

    /** @param array<string, scalar|null> $args */
    public function sql(string $sql, array $args = []): \PDOStatement
    {
        $this->pdo->exec('SET search_path TO "' . $this->schema . '", pg_catalog');
        $statement = $this->pdo->prepare($sql); $statement->execute($args); return $statement;
    }

    public function count(string $table): int { return (int) $this->sql('SELECT count(*) FROM ' . $table)->fetchColumn(); }
}
