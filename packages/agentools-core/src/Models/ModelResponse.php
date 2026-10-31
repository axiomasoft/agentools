<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models;

use AgenToolsPlatform\Core\Common\InvocationId;
use AgenToolsPlatform\Core\Common\PlatformError;

final readonly class ModelResponse
{
    /**
     * @param array<array-key, ContentPart> $parts
     * @param array<array-key, ToolProposal> $proposals
     */
    public function __construct(public InvocationId $invocationId, public Deployment $requestedDeployment,
        public ?Deployment $actualDeployment, public ?string $providerRequestId, public FinishReason $finishReason,
        public array $parts = [], public array $proposals = [], public JsonObject|StructuredValue|null $structured = null,
        public ?string $refusal = null, public ModelUsage $usage = new \AgenToolsPlatform\Core\Models\ModelUsage(),
        public string $adapterVersion = 'transport/v1', public ?PlatformError $error = null,
        public bool $partial = false, public ?ContinuationRef $continuation = null, public ?int $latencyMs = null)
    {
        $invocationId->tenant->assertSame($requestedDeployment->ref->tenant);
        if ($actualDeployment !== null) { $invocationId->tenant->assertSame($actualDeployment->ref->tenant); }
        foreach ($parts as $part) {
            if ($part->ref !== null) { $invocationId->tenant->assertSame($part->ref->tenant); }
        }
        $proposalRefs = [];
        foreach ($proposals as $proposal) {
            $invocationId->assertSame($proposal->invocationId);
            if (in_array($proposal->providerProposalRef, $proposalRefs, true)) {
                throw new \AgenToolsPlatform\Core\Common\IntegrityConflict('Duplicate provider proposal reference');
            }
            $proposalRefs[] = $proposal->providerProposalRef;
        }
        if ($error !== null) { $invocationId->tenant->assertSame($error->traceRef->tenant); }
        if ($continuation !== null) { $invocationId->tenant->assertSame($continuation->encryptedRef->tenant); }
        if (!array_is_list($parts) || !array_is_list($proposals) || $adapterVersion === ''
            || ($finishReason === FinishReason::Refusal && ($refusal === null || $refusal === ''))
            || ($refusal !== null && $finishReason !== FinishReason::Refusal)
            || ($finishReason === FinishReason::Error && $error === null)
            || ($error?->certainty === \AgenToolsPlatform\Core\Common\Certainty::Unknown && $finishReason !== FinishReason::Unknown)
            || ($finishReason === FinishReason::Completed && ($partial || $proposals !== [] || $error !== null))
            || ($finishReason === FinishReason::ToolProposal && ($partial || $proposals === [] || $error !== null))
            || ($latencyMs !== null && $latencyMs < 0)) {
            throw new \InvalidArgumentException('Inconsistent model response');
        }
    }

    public function assertFor(ModelRequest $request, Deployment $deployment): void
    {
        $request->invocationId->assertSame($this->invocationId);
        $deployment->ref->assertSame($this->requestedDeployment->ref);
        if (!$deployment->sameBinding($this->requestedDeployment) || $deployment->model !== $this->requestedDeployment->model) {
            throw new \AgenToolsPlatform\Core\Common\IntegrityConflict('Response requested deployment changed');
        }
    }
}
