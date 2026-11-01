<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, Ref, UtcInstant};

/** Owner output projection: only modelView becomes model data; other views stay referenced. */
final readonly class ToolResultView
{
    /** @param array<array-key, Ref> $evidenceRefs */
    public function __construct(public Ref $rawResultRef, public Ref $modelViewRef, public string $modelView, public Ref $userViewRef,
        public Ref $auditViewRef, public Classification $classification, public UtcInstant $freshAt, public array $evidenceRefs=[])
    {
        foreach ([$modelViewRef,$userViewRef,$auditViewRef,...$evidenceRefs] as $ref) { $rawResultRef->tenant->assertSame($ref->tenant); }
        if (!array_is_list($evidenceRefs)) { throw new \InvalidArgumentException('Evidence refs must be a list'); }
    }
    public function block(Ref $id,Ref $source,string $sourceVersion,Ref $owner,string $purpose,UtcInstant $expiry,
        string $toolId,int $priority,CanonicalHasher $hasher): ContextBlock
    {
        return new \AgenToolsPlatform\Core\Context\ContextBlock($id,BlockKind::ToolResult,$source,$sourceVersion,$owner,
            TrustClass::Untrusted,$this->classification,$purpose,$this->modelViewRef,$this->modelView,$expiry,true,$priority,$hasher,$this->evidenceRefs,
            new \AgenToolsPlatform\Core\Context\Provenance(SourceKind::Tool,freshAt:$this->freshAt,rawResultRef:$this->rawResultRef,toolId:$toolId,
                userViewRef:$this->userViewRef,auditViewRef:$this->auditViewRef));
    }
}
