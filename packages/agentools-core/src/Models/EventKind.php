<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models;

enum EventKind: string
{
    case Started = 'started';
    case TextDelta = 'text_delta';
    case ProposalDelta = 'proposal_delta';
    case Usage = 'usage';
    case Terminal = 'terminal';
}
