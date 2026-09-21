<?php

declare(strict_types=1);

namespace AgenTools\Contracts;

use AgenTools\Confirmation\ConfirmationRecord;

/** Journal-backed authoritative confirmation-record projection seam. */
interface ConfirmationRecordStore
{
    public function save(ConfirmationRecord $record): void;

    public function load(string $proposalId, int $proposalVersion): ?ConfirmationRecord;
}
