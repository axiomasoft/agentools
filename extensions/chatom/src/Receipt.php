<?php

declare(strict_types=1);

namespace AgenToolsPlatform\ChatomBridge;

use AgenToolsPlatform\Core\Common\{IntegrityConflict, Outcome, PayloadHash, Ref};
use AgenToolsPlatform\Postgres\Delivery\DeliverySubmission;

/** Destination evidence only; unknown never means authoritative absence. */
final readonly class Receipt
{
    public function __construct(public Ref $destination, public string $key, public PayloadHash $hash,
        public Outcome $outcome, public ?Ref $message)
    {
        if ($key === '' || strlen($key) > 128 || (($outcome === Outcome::Unknown) !== ($message === null))) {
            throw new \InvalidArgumentException('Known destination outcomes require an evidence ref');
        }
        if ($message !== null) { $destination->tenant->assertSame($message->tenant); }
    }
    public function assertSubmission(DeliverySubmission $submission): void
    {
        $submission->destination->assertSame($this->destination);
        if ($this->key !== $submission->key || $this->hash->value !== $submission->hash->value) {
            throw new IntegrityConflict('DELIVERY_PAYLOAD_CONFLICT');
        }
    }
}
