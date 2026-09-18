<?php

declare(strict_types=1);

namespace AgenTools\Identity;

enum NegotiationMode: string
{
    case AgenTools = 'agentools';
    case CoreProtocol = 'core_protocol';
    case Rejected = 'rejected';
}
