<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models;

use AgenToolsPlatform\Core\Common\InvocationId;

/** Provider reference is stable within this tenant invocation, never an Action grant. */
final readonly class ToolProposal
{
    public function __construct(public InvocationId $invocationId, public string $providerProposalRef,
        public string $tool, public JsonObject $arguments)
    {
        if ($providerProposalRef === '' || $tool === '') { throw new \InvalidArgumentException('Proposal identity and tool are required'); }
    }
}
