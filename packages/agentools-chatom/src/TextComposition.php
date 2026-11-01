<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Chatom;

use AgenTools\Chatom\Bridge\BridgeKeys;
use AgenTools\Chatom\Data\{DeliveryIntent, IncomingInput};
use AgenTools\Chatom\Nodes\Shape;
use AgenTools\Chatom\Platform\{IntakeContext, PostgresDeliveryOwner};
use AgenToolsPlatform\Core\Budget\BudgetPort;
use AgenToolsPlatform\Core\Common\{CanonicalHasher, ContractViolation, ErrorCode, IdentityKeys, InputPayload, PayloadHash, Ref, RunStatus, SourceIdentity, UtcInstant};
use AgenToolsPlatform\Core\Context\{CompiledContext, ContextBlock, ContextSnapshot, Provenance, ReadContextCompiler, SourceKind, TokenCounter};
use AgenToolsPlatform\Core\Models\{ContentPart, GenerationOptions, MessageRole, ModelMessage, ModelRequest, ModelTransport};
use AgenToolsPlatform\Core\Runtime\{AdvanceLease, AdvanceRun, ContinuationPolicy, InvocationGuard, InvocationPayloads, InvocationPlan, ProposalDispatcher};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Claims\{ClaimPolicy, ClaimRepository};
use AgenToolsPlatform\Postgres\Context\SnapshotRepository;
use AgenToolsPlatform\Postgres\Delivery\DeliveryRepository;
use AgenToolsPlatform\Postgres\Models\InvocationRepository;
use AgenToolsPlatform\Postgres\Runtime\{RepositoryContext, RunRepository, SessionRepository};

/** One input/context/response composition over the existing native journals, claims and delivery owner. */
final readonly class TextComposition
{
    /** @param \Closure(string):ExecutionContext $context */
    public function __construct(private RepositoryContext $environment, private \Closure $context,
        private TextProfile $profile, private NativeHistory $history, private ReadContextCompiler $compiler,
        private SnapshotRepository $snapshots, private TokenCounter $counter, private InvocationPayloads $payloads,
        private InvocationPayloads $exports, private InvocationRepository $invocations, private ModelTransport $model,
        private BudgetPort $budget, private InvocationGuard $guard, private ProposalDispatcher $actions,
        private DeliveryRepository $deliveries, private PostgresDeliveryOwner $owner, private UtcInstant $expiresAt, private ClaimPolicy $claims) {}

    public function enable(): void
    {
        $context = ($this->context)('chatom.intake');
        $this->profile->bind($context, new SessionRepository($this->environment), $this->environment->hasher);
    }

    /** Called by the existing PostgresInbox, before the real owner intake commits and acknowledges. */
    public function prepare(IncomingInput $input): IntakeContext
    {
        $context = ($this->context)('chatom.intake'); $hasher = $this->environment->hasher;
        $this->profile->bind($context, new SessionRepository($this->environment), $hasher);
        if ($input->target->assistant !== $this->profile->assistant->value
            || $input->target->policyRevision !== $context->identity->policyRevision) {
            throw new ContractViolation(ErrorCode::StaleContext);
        }
        $inputRef = new Ref($context->session->tenant, 'chatom:input:'.$input->key());
        $payload = new InputPayload($inputRef, new PayloadHash('sha-256:'.$input->hash()),
            new Ref($inputRef->tenant, $context->session->value), new Ref($inputRef->tenant, 'chatom:message:'.$input->source->id));
        $this->payloads->put($context, $inputRef, $payload->contentHash, BridgeKeys::encode($input->toArray()));
        $source = new Ref($inputRef->tenant, 'chatom:source:'.BridgeKeys::hash(['space' => $input->space->value, 'source' => $input->source->toArray()]));
        $this->deliveries->bindInput($context, $inputRef, $source, $input->revision);
        $compiled = $this->compiled($context, $input);
        $this->snapshots->put($context, $compiled);
        return new IntakeContext($context, $compiled->snapshot, $this->profile->assistant, $payload);
    }

    /** One bounded native advance. A completed Run only stages the saved export, never the model. */
    public function advance(IncomingInput $input): string
    {
        $context = ($this->context)('chatom.intake'); $hasher = $this->environment->hasher;
        $keys = new IdentityKeys($hasher); $source = new SourceIdentity($this->profile->assistant, 'chatom.initial.v1', $input->key());
        $turn = $keys->turn($source); $runId = $keys->run($turn);
        $runs = new RunRepository($this->environment);
        $run = $runs->get($context, $runId) ?? throw new ContractViolation(ErrorCode::CapabilityUnavailable);
        if ($run->status === RunStatus::Completed) { return $this->stage($context, $runId); }
        $compiled = $this->compiled($context, $input);
        $this->snapshots->get($context, $compiled->snapshot);
        $deadline = $this->prepared($context, $input)['deadline'];
        if (!is_string($deadline)) { throw new ContractViolation(ErrorCode::IntegrityConflict); }
        $invocation = $keys->invocation($runId, 'text', 1);
        $request = new ModelRequest($invocation, [new ModelMessage(MessageRole::User, [ContentPart::text($compiled->rendered)])],
            $compiled->snapshot->ref, $compiled->snapshot->contentHash, $this->profile->credential, new UtcInstant($deadline),
            system: $this->profile->system, policy: $this->profile->policy,
            generation: new GenerationOptions(maxOutputTokens: $this->profile->target->requestedMaxOutput));
        $destination = new Ref($runId->tenant, 'chatom:destination:'.BridgeKeys::hash([
            'space' => $input->space->value, 'channel' => $input->source->channelId()]));
        $plan = new InvocationPlan($runId, $turn, 'text', $request, new Ref($runId->tenant, 'request:'.$invocation->value),
            $this->profile->deployment, $compiled->snapshot, $this->profile->bundle,
            new Ref($runId->tenant, 'chatom:input:'.$input->key()), $destination, [], new ContinuationPolicy(1, 0),
            $this->counter->count($compiled->rendered) + $request->generation->maxOutputTokens, null, null, $hasher);
        $bytes = BridgeKeys::encode(['rendered' => $compiled->rendered, 'plan' => $plan->identity()]);
        $this->payloads->put($context, $plan->requestRef, new PayloadHash('sha-256:'.hash('sha256', $bytes)), $bytes);
        if ($run->status === RunStatus::Accepted) { $run = $runs->beginPreparation($context, $plan->snapshot, $runId, $run->version); }
        $claim = (new ClaimRepository($this->environment, $this->claims))->claim($context, $plan->snapshot, $runId, $run->version);
        if ($claim === null) { return 'busy'; }
        $lease = new AdvanceLease($runId, $claim->state->version, $claim->state->fencingEpoch, $claim->owner);
        $saved = (new AdvanceRun($this->invocations, $this->model, $this->budget, $this->guard, $this->payloads,
            $this->actions, $hasher))->advance($context, $lease, $plan);
        return $saved->stage() === 'complete' ? $this->stage($context, $runId) : $saved->stage();
    }

    private function stage(ExecutionContext $context, \AgenToolsPlatform\Core\Common\RunId $run): string
    {
        $saved = $this->invocations->published($context, $run) ?? throw new ContractViolation(ErrorCode::CapabilityUnavailable);
        $settlement = $this->invocations->settlement($context, $run);
        $this->budget->settle($settlement['reservation'], $settlement['usage']);
        $delivery = ($this->context)('chatom.delivery');
        $bytes = $this->exports->get($delivery, $saved->payload);
        $saved->hash->assertSame(new PayloadHash('sha-256:'.hash('sha256', $bytes)));
        $intent = DeliveryIntent::restore(Shape::record(json_decode($bytes, true, 128, JSON_THROW_ON_ERROR)));
        if ($intent->key() !== $saved->deliveryKey) { throw new ContractViolation(ErrorCode::IntegrityConflict); }
        $this->deliveries->submit($delivery, $this->owner->submission($intent, $delivery));
        return $saved->deliveryKey;
    }

    private function compiled(ExecutionContext $context, IncomingInput $input): CompiledContext
    {
        $key = new Ref($context->session->tenant, 'chatom:compiled:'.$input->key());
        $saved = $this->payloads->find($context, $key);
        if ($saved !== null) { return $this->restore($context, Shape::record(json_decode($saved, true, 128, JSON_THROW_ON_ERROR))); }
        $hasher = $this->environment->hasher; $branch = new Ref($key->tenant, $context->session->value);
        $message = $this->history->current($context, $branch, $input->source->id, $input->revision);
        $blocks = [];
        foreach (['policy' => $this->profile->policy, 'profile' => $this->profile->system] as $kind => $text) {
            $blocks[] = new ContextBlock(new Ref($key->tenant, 'chatom:block:'.$kind), $kind, $this->profile->bundle->ref,
                $this->profile->version, $this->profile->bundle->ref, 'policy', 'private', $context->purpose,
                $this->profile->bundle->ref, $text, $this->expiresAt, true, 100, $hasher);
        }
        $blocks[] = new ContextBlock(new Ref($key->tenant, 'chatom:block:input'), 'input', $message->source->ref,
            $message->source->version, $branch, 'untrusted', 'private', $context->purpose, $message->source->ref,
            $message->text, $this->expiresAt, true, 100, $hasher, provenance: new Provenance(SourceKind::History, [$message->source->ref]));
        foreach ($this->history->recent($context, $branch, 0, 16)->messages as $recent) {
            if ($recent->source->turn >= $message->source->turn) { continue; }
            $blocks[] = new ContextBlock(new Ref($key->tenant, 'chatom:block:'.$recent->source->turn), 'recent', $recent->source->ref,
                $recent->source->version, $branch, 'untrusted', 'private', $context->purpose, $recent->source->ref,
                $recent->text, $this->expiresAt, false, 50, $hasher, provenance: new Provenance(SourceKind::History, [$recent->source->ref]));
        }
        $keys = new IdentityKeys($hasher); $turn = $keys->turn(new SourceIdentity($this->profile->assistant, 'chatom.initial.v1', $input->key()));
        $plan = $this->compiler->buildPlan($context, $turn, $keys->run($turn), $this->profile->bundle, $blocks, $this->guard->now());
        $compiled = $this->compiler->finalize($plan, $this->profile->target, $this->counter, $this->guard->now());
        $bytes = BridgeKeys::encode(['rendered' => $compiled->rendered, 'manifest' => $compiled->manifestJson,
            'snapshot' => $compiled->snapshot->toArray(), 'deadline' => $this->expiresAt->value]);
        $this->payloads->put($context, $key, new PayloadHash('sha-256:'.hash('sha256', $bytes)), $bytes);
        return $compiled;
    }

    /** @return array<string,mixed> */
    private function prepared(ExecutionContext $context, IncomingInput $input): array
    {
        return Shape::record(json_decode($this->payloads->get($context, new Ref($context->session->tenant,
            'chatom:compiled:'.$input->key())), true, 128, JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $data */
    private function restore(ExecutionContext $context, array $data): CompiledContext
    {
        $s = Shape::record($data['snapshot'] ?? null);
        $ref = Shape::record($s['snapshot_ref'] ?? null);
        $id = $ref['id'] ?? null; $revision = $s['revision'] ?? null;
        $content = $s['content_hash'] ?? null; $scope = $s['scope_hash'] ?? null;
        $rendered = $data['rendered'] ?? null; $manifest = $data['manifest'] ?? null;
        if (!is_string($id) || !is_int($revision) || !is_string($content) || !is_string($scope)
            || !is_string($rendered) || !is_string($manifest) || ($ref['kind'] ?? null) !== 'ref'
            || ($ref['tenant_id'] ?? null) !== $context->session->tenant->value) {
            throw new ContractViolation(ErrorCode::IntegrityConflict);
        }
        $compiled = new CompiledContext(new ContextSnapshot(new Ref($context->session->tenant, $id),
            $revision, new PayloadHash($content), new PayloadHash($scope)), $rendered, $manifest);
        $compiled->assertIntegrity($context, $this->environment->hasher);
        return $compiled;
    }
}
