<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Runtime;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, PayloadHash, Ref, RunId, TurnId};
use AgenToolsPlatform\Core\Context\ContextSnapshot;
use AgenToolsPlatform\Core\Models\{Deployment, ModelRequest};
use AgenToolsPlatform\Core\Profiles\{BundleRef, DefinitionRef};

/** Immutable compiled input. Request bytes live at the host's immutable requestRef. */
final readonly class InvocationPlan
{
    public PayloadHash $requestHash;

    /** @param array<string, DefinitionRef> $tools */
    public function __construct(public RunId $runId, public TurnId $turnId, public string $stepKey, public ModelRequest $request,
        public Ref $requestRef, public Deployment $deployment, public ContextSnapshot $snapshot,
        public BundleRef $bundle, public Ref $businessOperation, public Ref $destination,
        public array $tools, public ContinuationPolicy $bounds, public int $estimatedUnits,
        public ?string $parentInvocation, public ?string $reason, CanonicalHasher $hasher)
    {
        foreach ([$turnId, $request->invocationId, $requestRef, $deployment->ref, $snapshot->ref, $bundle->ref, $businessOperation, $destination] as $ref) {
            $runId->tenant->assertSame($ref->tenant);
        }
        $snapshot->ref->assertSame($request->contextSnapshotRef);
        $snapshot->contentHash->assertSame($request->contextSnapshotHash);
        if ($stepKey === '' || strlen($stepKey) > 128 || $estimatedUnits < 0 || $estimatedUnits > $bounds->maxEstimatedUnits
            || (($parentInvocation === null) !== ($reason === null)) || $reason === ''
            || $parentInvocation === $request->invocationId->value) {
            throw new \InvalidArgumentException('Stable step, bounded estimate and explicit replacement reason required');
        }
        foreach ($request->tools as $tool) {
            if (!isset($tools[$tool->name])) { throw new \InvalidArgumentException('Projected tool needs pinned definition'); }
        }
        // JSON encoding uses only the closed model value graph. Cancellation is an attempt signal.
        $data = get_object_vars($request);
        unset($data['cancellation']);
        $this->requestHash = $hasher->hash($runId->tenant, 'model-request',
            json_decode(json_encode($data, JSON_THROW_ON_ERROR), false, 128, JSON_THROW_ON_ERROR));
    }

    /**
     * No raw messages, arguments, credentials or provider state in technical metadata.
     * @return array<string, mixed>
     */
    public function identity(): array
    {
        $tools = [];
        foreach ($this->tools as $name => $definition) { $tools[$name] = $definition->toArray(); }
        return ['turn_id' => $this->turnId->value, 'run_id' => $this->runId->value, 'step_key' => $this->stepKey,
            'invocation_id' => $this->request->invocationId->value, 'request_ref' => $this->requestRef->value,
            'request_hash' => $this->requestHash->value, 'snapshot' => $this->snapshot->toArray(), 'bundle' => $this->bundle->toArray(),
            'deployment' => ['ref' => $this->deployment->ref->value, 'provider' => $this->deployment->provider,
                'account' => $this->deployment->account, 'region' => $this->deployment->region, 'model' => $this->deployment->model],
            'business_operation' => $this->businessOperation->value, 'destination' => $this->destination->value,
            'tools' => (object) $tools, 'bounds' => $this->bounds->toArray(), 'estimated_units' => $this->estimatedUnits,
            'deadline' => $this->request->deadline->value, 'parent_invocation' => $this->parentInvocation, 'reason' => $this->reason];
    }
}
