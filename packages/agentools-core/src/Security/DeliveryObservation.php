<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Security;

use AgenToolsPlatform\Core\Common\{PayloadHash,Ref};

final readonly class DeliveryObservation
{
    public function __construct(public Ref $receipt,public Ref $payload,public PayloadHash $hash,public DeliveryState $state,
        public ?int $deliveredBytes,public ?int $heardBytes=null,public int $confirmedPrefixBytes=0)
    {
        $receipt->tenant->assertSame($payload->tenant);
        if (($state===DeliveryState::Unknown)!==($deliveredBytes===null) || ($deliveredBytes!==null && $deliveredBytes<0)
            || $confirmedPrefixBytes<0 || ($heardBytes!==null && ($heardBytes<0 || $heardBytes>($deliveredBytes??$confirmedPrefixBytes)))
            || ($deliveredBytes!==null && $confirmedPrefixBytes>$deliveredBytes)) { throw new \InvalidArgumentException('Unknown delivery/hearing cannot be fabricated as zero or success'); }
    }
    /** @return array<string,mixed> */
    public function toArray(): array { return ['receipt_ref'=>$this->receipt->toArray(),'payload_ref'=>$this->payload->toArray(),'hash'=>$this->hash->value,
        'state'=>$this->state->value,'delivered_bytes'=>$this->deliveredBytes,'heard_bytes'=>$this->heardBytes,'confirmed_prefix_bytes'=>$this->confirmedPrefixBytes]; }
}
