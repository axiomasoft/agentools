<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models\Routing;

use AgenToolsPlatform\Core\Common\{InvocationId, Ref};

final readonly class FallbackState
{
    /**
     * @param list<Ref> $actions
     * @param list<string> $attemptedDeployments
     */
    public function __construct(public InvocationId $invocation, public Ref $bundle, public Ref $goal,
        public array $actions, public string $deployment, public Ref $failureEvent,
        public bool $disclosed, public ?Ref $unknownEffect = null, public ?Ref $disclosedSegment = null,
        public array $attemptedDeployments = [])
    {
        foreach (array_merge([$bundle,$goal,$failureEvent], $actions,
            $unknownEffect === null ? [] : [$unknownEffect], $disclosedSegment === null ? [] : [$disclosedSegment]) as $ref) {
            $invocation->tenant->assertSame($ref->tenant);
        }
        if ($deployment === '' || ($disclosed && $disclosedSegment === null)) { throw new \InvalidArgumentException('Fallback origin and disclosure attribution required'); }
    }
}
