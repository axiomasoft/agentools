<?php

declare(strict_types=1);

namespace AgenTools\Confirmation;

enum ProposalState: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Revoked = 'revoked';
    case Expired = 'expired';
    case Superseded = 'superseded';
}
