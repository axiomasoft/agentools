<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Core\Models\Routing;
use AgenToolsPlatform\Core\Common\{InvocationId,Ref};

/** Host disclosure handoff, not a receiver delivery/understanding receipt. */
final readonly class OutputChunk
{
    public function __construct(public Ref $segment,public InvocationId $invocation,public Ref $deployment,
        public string $requestedModel,public ?string $actualModel,public int $offset,public string $text) {}
}
