<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Loqum\Delivery;

use AgenToolsPlatform\Core\Common\{PayloadHash, Ref, SessionId, UtcInstant};

/** Immutable affirmative host event. A transcript/string/LLM response cannot be used as this source. */
final readonly class ApprovalEvent
{
    public function __construct(public Ref $source,public SessionId $session,public Ref $actor,public Ref $proposal,
        public PayloadHash $bindingHash,public PayloadHash $previewHash,public PayloadHash $inputHash,
        public string $policyRevision,public int $mediaEpoch,public int $contextRevision,
        public Ref $presentationReceipt,public UtcInstant $expiresAt)
    {
        foreach([$session,$actor,$proposal,$presentationReceipt] as $ref) { $source->tenant->assertSame($ref->tenant); }
        if ($policyRevision==='' || min($mediaEpoch,$contextRevision)<1 || max($mediaEpoch,$contextRevision)>9007199254740991) {
            throw new \InvalidArgumentException('Explicit bound approval event required');
        }
    }
    /** @return array<string,mixed> */
    public function manifest(): array
    {
        return ['source'=>$this->source->toArray(),'session'=>$this->session->toArray(),'actor'=>$this->actor->toArray(),
            'proposal'=>$this->proposal->toArray(),'binding_hash'=>$this->bindingHash->value,'preview_hash'=>$this->previewHash->value,
            'input_hash'=>$this->inputHash->value,'policy_revision'=>$this->policyRevision,'media_epoch'=>$this->mediaEpoch,
            'context_revision'=>$this->contextRevision,'presentation_receipt'=>$this->presentationReceipt->toArray(),'expires_at'=>$this->expiresAt->value];
    }
}
