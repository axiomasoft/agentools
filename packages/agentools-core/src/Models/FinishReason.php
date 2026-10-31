<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models;

enum FinishReason: string
{
    case Completed = 'completed';
    case ToolProposal = 'tool_proposal';
    case Length = 'length';
    case Refusal = 'refusal';
    case Cancelled = 'cancelled';
    case Error = 'error';
    case Unknown = 'unknown';
}
