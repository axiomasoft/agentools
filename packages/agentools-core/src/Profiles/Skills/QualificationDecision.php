<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Skills;

enum QualificationDecision: string
{
    case Qualified = 'qualified';
    case Rejected = 'rejected';
    case InsufficientEvidence = 'insufficient_evidence';
}
