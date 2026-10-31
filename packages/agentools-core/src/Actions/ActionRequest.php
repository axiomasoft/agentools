<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Actions;

use AgenToolsPlatform\Core\Common\CanonicalHasher;
use AgenToolsPlatform\Core\Common\PayloadHash;
use AgenToolsPlatform\Core\Common\ProposalId;
use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Common\UtcInstant;
use AgenToolsPlatform\Core\Profiles\DefinitionRef;
use AgenToolsPlatform\Core\Security\ExecutionContext;

/** Submission value, usable directly without an Assistant or a model proposal. */
final readonly class ActionRequest
{
    public PayloadHash $scopeHash;
    public PayloadHash $requestHash;

    public function __construct(
        public DefinitionRef $definition,
        public Ref $originRef,
        public ?ProposalId $proposalId,
        public Ref $idempotencyKey,
        public Ref $inputRef,
        public PayloadHash $inputHash,
        public ExecutionContext $context,
        public UtcInstant $deadline,
        CanonicalHasher $hasher,
    ) {
        foreach ([$originRef, $proposalId, $idempotencyKey, $inputRef] as $ref) {
            if ($ref !== null) {
                $context->identity->tenant->assertSame($ref->tenant);
            }
        }
        $this->scopeHash = $context->scopeHash($hasher);
        $this->requestHash = $hasher->hash($context->identity->tenant, 'action-request', $this->hashInput());
    }

    /** @return array<string, mixed> */
    private function hashInput(): array
    {
        return ['definition' => $this->definition->toArray(), 'origin_ref' => $this->originRef->toArray(),
            'proposal_id' => $this->proposalId?->toArray(), 'idempotency_key' => $this->idempotencyKey->toArray(),
            'input_ref' => $this->inputRef->toArray(), 'input_hash' => $this->inputHash->value,
            'scope_hash' => $this->scopeHash->value, 'deadline' => $this->deadline->value];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->hashInput() + ['execution_context' => $this->context->toArray(), 'request_hash' => $this->requestHash->value];
    }
}
