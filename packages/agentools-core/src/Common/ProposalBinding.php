<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Common;

/** Saved mapping; the adapter persists it before dispatch/confirmation IO. */
final readonly class ProposalBinding
{
    public function __construct(public ProposalId $proposalId, public ActionId $actionId,
        public PayloadHash $inputHash, public ?Ref $confirmationRef)
    {
        $proposalId->tenant->assertSame($actionId->tenant);
        if ($confirmationRef !== null) {
            $proposalId->tenant->assertSame($confirmationRef->tenant);
        }
    }

    public function assertReplay(self $other): void
    {
        $this->proposalId->assertSame($other->proposalId);
        $this->actionId->assertSame($other->actionId);
        $this->inputHash->assertSame($other->inputHash);
        if (($this->confirmationRef === null) !== ($other->confirmationRef === null)) {
            throw new \AgenToolsPlatform\Core\Common\IntegrityConflict('Saved confirmation mapping changed');
        }
        if ($this->confirmationRef !== null && $other->confirmationRef !== null) {
            $this->confirmationRef->assertSame($other->confirmationRef);
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['proposal_id' => $this->proposalId->toArray(), 'action_id' => $this->actionId->toArray(),
            'input_hash' => $this->inputHash->value, 'confirmation_ref' => $this->confirmationRef?->toArray()];
    }
}
