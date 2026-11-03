<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Loqum\Delivery;

use AgenToolsPlatform\Core\Common\Ref;

/** Authenticated media-owner evidence, not model output or a synthesis completion. */
final readonly class PlayoutReceipt
{
    public function __construct(public Ref $ref, public Ref $response, public int $responseVersion,
        public Ref $segment, public Ref $attempt, public int $mediaEpoch, public int $offset,
        public string $status, public string $evidenceLevel)
    {
        foreach ([$response,$segment,$attempt] as $link) { $ref->tenant->assertSame($link->tenant); }
        if (min($responseVersion,$mediaEpoch)<1 || $offset<0 || max($responseVersion,$mediaEpoch,$offset)>9007199254740991
            || !in_array($status,['completed','interrupted','failed'],true)
            || !in_array($evidenceLevel,['server_estimate','client_ack'],true)) { throw new \InvalidArgumentException('Explicit bounded playout evidence required'); }
    }
    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['receipt_ref'=>$this->ref->toArray(),'response_ref'=>$this->response->toArray(),'response_version'=>$this->responseVersion,
            'segment_ref'=>$this->segment->toArray(),'attempt_ref'=>$this->attempt->toArray(),'media_epoch'=>$this->mediaEpoch,
            'delivered_offset'=>$this->offset,'offset_unit'=>'utf8_bytes','status'=>$this->status,'evidence_level'=>$this->evidenceLevel];
    }
}
