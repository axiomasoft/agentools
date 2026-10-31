<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models;

use AgenToolsPlatform\Core\Common\InvocationId;

/** Exactly one typed payload for each ordered event. Proposal arguments remain partial JSON text. */
final readonly class ModelEvent
{
    public function __construct(public InvocationId $invocationId, public int $sequence, public EventKind $kind,
        public ?string $delta = null, public ?string $providerProposalRef = null, public ?string $tool = null,
        public ?ModelUsage $usage = null, public ?ModelResponse $response = null)
    {
        $valid = match ($kind) {
            EventKind::Started => $delta === null && $providerProposalRef === null && $tool === null && $usage === null && $response === null,
            EventKind::TextDelta => $delta !== null && $providerProposalRef === null && $tool === null && $usage === null && $response === null,
            EventKind::ProposalDelta => $delta !== null && $providerProposalRef !== null && $providerProposalRef !== ''
                && $tool !== null && $tool !== '' && $usage === null && $response === null,
            EventKind::Usage => $delta === null && $providerProposalRef === null && $tool === null && $usage !== null && $response === null,
            EventKind::Terminal => $delta === null && $providerProposalRef === null && $tool === null && $usage === null && $response !== null,
        };
        if ($sequence < 1 || !$valid) { throw new \InvalidArgumentException('Invalid stream event payload'); }
        if ($response !== null) { $invocationId->assertSame($response->invocationId); }
    }
}
