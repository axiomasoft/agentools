<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Budget;

use AgenToolsPlatform\Core\Common\Ref;

/** Technical owner projection only, with no balances, debit or local quota. */
final readonly class ReservationCheckpoint
{
    public function __construct(public AdmissionRequest $request, public Ref $reservation, public ReservationStatus $status,
        public Exposure $exposure, public ?Ref $notSentEvidence = null)
    {
        $request->operationKey->tenant->assertSame($reservation->tenant);
        if ($notSentEvidence !== null) { $reservation->tenant->assertSame($notSentEvidence->tenant); }
        if (($exposure === Exposure::NotSent) !== ($notSentEvidence !== null)) { throw new \InvalidArgumentException('Proven not_sent evidence required'); }
    }
    public function next(ReservationStatus $status): self
    { return new \AgenToolsPlatform\Core\Budget\ReservationCheckpoint($this->request,$this->reservation,$status,$this->exposure,$this->notSentEvidence); }
}
