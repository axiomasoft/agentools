<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\Knowledge;

use AgenToolsPlatform\Core\Context\{ContextAccess,ContextBlock,ContextSnapshot,SnapshotAccess};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Knowledge\Decision;

/** Composes the existing host policy with fresh exact source and graph state. */
final readonly class ContextPolicy implements ContextAccess,SnapshotAccess
{
    public function __construct(private KnowledgeJourney $journey) {}
    public function permits(ExecutionContext $context, ContextBlock|ContextSnapshot $value): bool
    {
        $j=$this->journey;
        if ($j->owner->current($j->scope)!==Decision::Allowed) { return false; }
        if ($value instanceof ContextSnapshot) {
            return $j->snapshotNode!==null && $j->snapshotNode->ref===$value->ref->value
                && $j->snapshotNode->hash===substr($value->contentHash->value,8)
                && $j->graph->usable($j->scope,$j->snapshotNode)===Decision::Allowed;
        }
        return $j->host->policy->permits($context,$value) && ($value->kind!=='evidence' || $j->fresh());
    }
}
