<?php

declare(strict_types=1);

namespace AgenTools\Contracts;

use AgenTools\Confirmation\ApprovalEvidence;
use AgenTools\Confirmation\ConfirmationRecord;

/** Journal-backed projection seam; never a second approval truth. */
interface ApprovalEvidenceReader
{
    public function read(ConfirmationRecord $record): ?ApprovalEvidence;
}
