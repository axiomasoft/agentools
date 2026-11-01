<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context;

use AgenToolsPlatform\Core\Common\{Ref, UtcInstant};

/** Trusted source adapter metadata; never parsed from model text. */
final readonly class Provenance
{
    /** @param array<array-key, Ref> $messageIds */
    public function __construct(public SourceKind $sourceKind = SourceKind::Host, public array $messageIds = [],
        public ?Ref $throughTurn = null, public ?string $summaryVersion = null, public ?UtcInstant $freshAt = null,
        public ?Ref $rawResultRef = null, public ?string $toolId = null, public bool $relevant = true,
        public ?Ref $userViewRef = null, public ?Ref $auditViewRef = null)
    {
        if (!array_is_list($messageIds) || $summaryVersion === '' || $toolId === '') {
            throw new \InvalidArgumentException('Explicit provenance required');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['source_kind'=>$this->sourceKind->value, 'message_ids'=>array_map(static fn(Ref $ref): array=>$ref->toArray(),$this->messageIds),
            'through_turn'=>$this->throughTurn?->toArray(), 'summary_version'=>$this->summaryVersion,
            'fresh_at'=>$this->freshAt?->value, 'raw_result_ref'=>$this->rawResultRef?->toArray(), 'tool_id'=>$this->toolId,
            'user_view_ref'=>$this->userViewRef?->toArray(), 'audit_view_ref'=>$this->auditViewRef?->toArray()];
    }
}
