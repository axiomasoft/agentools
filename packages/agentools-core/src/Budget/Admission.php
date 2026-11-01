<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Budget;

use AgenToolsPlatform\Core\Common\{Ref, UtcInstant};

final readonly class Admission
{
    /** @param array<string, int> $limits */
    public function __construct(public string $status, public ?Ref $admissionRef = null,
        public ?Ref $reservationRef = null, public ?UtcInstant $expiresAt = null,
        public string $ownerRevision = '', public array $limits = [], public ?string $reason = null)
    {
        if (!in_array($status, ['admitted', 'denied', 'unavailable', 'unknown', 'not_found'], true)
            || ($status === 'admitted' && ($admissionRef === null || $expiresAt === null || $ownerRevision === ''))) {
            throw new \InvalidArgumentException('Explicit authoritative admission outcome required');
        }
        if ($reservationRef !== null && $admissionRef !== null) { $admissionRef->tenant->assertSame($reservationRef->tenant); }
        foreach ($limits as $value) {
            if ($value < 0 || $value > 9007199254740991) { throw new \InvalidArgumentException('Invalid owner limit'); }
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['status' => $this->status, 'admission_ref' => $this->admissionRef?->value,
            'reservation_ref' => $this->reservationRef?->value, 'expires_at' => $this->expiresAt?->value,
            'owner_revision' => $this->ownerRevision, 'limits' => (object) $this->limits, 'reason' => $this->reason];
    }
}
