<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context;

use AgenToolsPlatform\Core\Common\{RunId, TurnId};
use AgenToolsPlatform\Core\Profiles\BundleRef;
use AgenToolsPlatform\Core\Security\ExecutionContext;

final readonly class ContextPlan
{
    /**
     * @param list<ContextBlock> $blocks
     * @param list<array<string, mixed>> $exclusions
     */
    public function __construct(public ExecutionContext $context, public TurnId $turn, public RunId $run,
        public BundleRef $bundle, public array $blocks, public array $exclusions,
        public SnapshotPins $pins = new \AgenToolsPlatform\Core\Context\SnapshotPins()) {}
}
