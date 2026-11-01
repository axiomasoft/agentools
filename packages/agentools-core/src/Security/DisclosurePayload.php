<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Security;

use AgenToolsPlatform\Core\Common\{PayloadHash,Ref};

/** Checked or explicitly risk-approved bytes; possession is not delivery acknowledgement. */
final readonly class DisclosurePayload
{
    public function __construct(public Ref $ref,public Ref $response,public int $responseVersion,public int $ordinal,public string $bytes,
        public PayloadHash $hash,public bool $prechecked,public ?Ref $checkRef,public ?Ref $liveApproval)
    {
        foreach ([$response,$checkRef,$liveApproval] as $r) { if ($r!==null) { $ref->tenant->assertSame($r->tenant); } }
        if ($responseVersion<1 || $ordinal<1 || ($prechecked && $checkRef===null) || (!$prechecked && $liveApproval===null)) { throw new \InvalidArgumentException('Bounded checked/risk-approved payload required'); }
    }
    /** @return array<string,mixed> */
    public function __debugInfo(): array { return ['payload_ref'=>$this->ref->value,'response_ref'=>$this->response->value,'ordinal'=>$this->ordinal,'hash'=>$this->hash->value,'bytes'=>strlen($this->bytes),'prechecked'=>$this->prechecked]; }
}
