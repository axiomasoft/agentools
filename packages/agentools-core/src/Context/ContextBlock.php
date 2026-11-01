<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, PayloadHash, Ref, UtcInstant};

/** Created by trusted host adapters. Source text never supplies its trust, purpose or grants. */
final readonly class ContextBlock
{
    public PayloadHash $hash;
    public string $kind;
    public string $trust;
    public string $classification;
    public BlockKind $kindTag;
    public TrustClass $trustTag;
    public Classification $classificationTag;
    /** @param array<array-key, Ref> $evidenceRefs */
    public function __construct(public Ref $ref, BlockKind|string $kind, public Ref $source, public string $sourceVersion,
        public Ref $owner, TrustClass|string $trust, Classification|string $classification, public string $purpose,
        public Ref $contentRef, public string $modelView, public UtcInstant $expiresAt,
        public bool $mandatory, public int $priority, CanonicalHasher $hasher, public array $evidenceRefs = [],
        public Provenance $provenance = new \AgenToolsPlatform\Core\Context\Provenance())
    {
        $this->kindTag = is_string($kind) ? BlockKind::from($kind) : $kind;
        $this->trustTag = is_string($trust) ? TrustClass::from($trust) : $trust;
        $this->classificationTag = is_string($classification) ? Classification::from($classification) : $classification;
        $this->kind = $this->kindTag->value; $this->trust = $this->trustTag->value; $this->classification = $this->classificationTag->value;
        foreach ([$source, $owner, $contentRef, ...$evidenceRefs] as $link) { $ref->tenant->assertSame($link->tenant); }
        foreach ([...$provenance->messageIds, $provenance->throughTurn, $provenance->rawResultRef,
            $provenance->userViewRef, $provenance->auditViewRef] as $link) {
            if ($link !== null) { $ref->tenant->assertSame($link->tenant); }
        }
        if (!array_is_list($evidenceRefs)
            || $sourceVersion === '' || $purpose === '' || $priority < 0
            || ($provenance->sourceKind !== SourceKind::Host && $provenance->sourceKind !== SourceKind::Policy
                && $this->trustTag !== TrustClass::Untrusted)
            || (in_array($this->kind, ['policy','input','working_state','promise','tool_result'], true) && !$mandatory)) {
            throw new \InvalidArgumentException('Explicit trusted context metadata and mandatory policy/input required');
        }
        $this->hash = $hasher->hash($ref->tenant, 'context-model-view', $modelView);
    }

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return ['id' => $this->ref->value, 'kind' => $this->kind, 'source_ref' => $this->source->value,
            'source_version' => $this->sourceVersion, 'owner_ref' => $this->owner->value, 'trust_class' => $this->trust,
            'classification' => $this->classification, 'purpose' => $this->purpose, 'content_ref' => $this->contentRef->value,
            'content_hash' => $this->hash->value, 'expires_at' => $this->expiresAt->value,
            'mandatory' => $this->mandatory, 'priority' => $this->priority,
            'evidence_refs' => array_map(static fn (Ref $ref): array => $ref->toArray(), $this->evidenceRefs),
            'provenance' => $this->provenance->toArray()];
    }
}
