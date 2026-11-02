<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context\History;

use AgenToolsPlatform\Core\Context\{ContextAccess, ContextBlock, SourceKind};
use AgenToolsPlatform\Core\Security\ExecutionContext;

/** Compose with the current owner guard so invalidation also wins after selection/rendering. */
final readonly class DerivedContextAccess implements \AgenToolsPlatform\Core\Context\ContextAccess
{
    public function __construct(private ContextAccess $owner, private Derivations $derivations) {}
    public function permits(ExecutionContext $context, ContextBlock $block): bool
    {
        if (!$this->owner->permits($context,$block)) { return false; }
        return !in_array($block->provenance->sourceKind,[SourceKind::Summary,SourceKind::Memory],true)
            || $this->derivations->usable($context,$block->ref);
    }
}
