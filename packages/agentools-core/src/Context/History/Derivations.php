<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context\History;

use AgenToolsPlatform\Core\Common\{Ref,RunId};
use AgenToolsPlatform\Core\Security\ExecutionContext;

/** Durable reverse dependencies/tombstones; payloads and external owner truth stay outside. */
interface Derivations
{
    /**
     * @param list<MessageRef> $sources
     * @param list<RunId> $completedRuns
     */
    public function register(ExecutionContext $context,Ref $derived,DerivedKind $kind,array $sources,array $completedRuns=[]): void;
    /** Current durable state, including invalidations during external view IO.
     * @phpstan-impure
     */
    public function usable(ExecutionContext $context,Ref $derived): bool;
    public function invalidate(ExecutionContext $context,MessageRef $source,string $reason): void;
}
