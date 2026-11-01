<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Runtime;

use AgenToolsPlatform\Core\Common\{ActionId, PayloadHash, ProposalId, UtcInstant};
use AgenToolsPlatform\Core\Profiles\DefinitionRef;

final readonly class SavedProposal
{
    public function __construct(public ProposalId $id, public ActionId $actionId, public int $ordinal,
        public string $providerId, public DefinitionRef $definition, public string $argumentsJson,
        public PayloadHash $inputHash, public UtcInstant $deadline)
    {
        $id->tenant->assertSame($actionId->tenant);
        if ($ordinal < 0 || $providerId === '') { throw new \InvalidArgumentException('Saved proposal identity required'); }
    }
}
