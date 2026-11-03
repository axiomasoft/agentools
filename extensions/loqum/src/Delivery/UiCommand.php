<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Loqum\Delivery;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, PayloadHash, Ref, UtcInstant};

/** Closed presentation-only allowlist. No server read, grading, send or money command. */
final readonly class UiCommand
{
    public PayloadHash $hash;
    public function __construct(public Ref $id, public string $type, public int $version, public string $schema,
        public int $contextRevision, public UtcInstant $expiresAt, public Ref $playbackAnchor,
        public Ref $payloadRef, public PayloadHash $payloadHash, CanonicalHasher $hasher)
    {
        $id->tenant->assertSame($playbackAnchor->tenant); $id->tenant->assertSame($payloadRef->tenant);
        if (!in_array($type,['highlight','scroll'],true) || $version!==1 || $schema!=='voice-ui/v1'
            || $contextRevision<1 || $contextRevision>9007199254740991) { throw new \InvalidArgumentException('Closed versioned presentation command required'); }
        $this->hash=$hasher->hash($id->tenant,'voice-ui-command',$this->manifest());
    }
    /** @return array<string,mixed> */
    public function manifest(): array
    {
        return ['id'=>$this->id->toArray(),'type'=>$this->type,'version'=>$this->version,'schema'=>$this->schema,
            'context_revision'=>$this->contextRevision,'expires_at'=>$this->expiresAt->value,'playback_anchor'=>$this->playbackAnchor->toArray(),
            'payload_ref'=>$this->payloadRef->toArray(),'payload_hash'=>$this->payloadHash->value];
    }
}
