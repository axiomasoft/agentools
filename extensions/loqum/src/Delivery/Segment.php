<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Loqum\Delivery;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, PayloadHash, Ref};

/** Immutable refs only. Offsets are conservative, host-verified UTF-8 text byte boundaries. */
final readonly class Segment
{
    public Ref $key;
    public PayloadHash $hash;
    /** @var list<int> */
    public array $boundaries;
    /** @param array<array-key,mixed> $boundaries */
    public function __construct(public Response $response, public string $id, public int $sequence,
        public Ref $displayRef, public PayloadHash $displayHash, public int $displayBytes,
        public Ref $speakableRef, public PayloadHash $speakableHash, public int $speakableBytes,
        array $boundaries, CanonicalHasher $hasher)
    {
        foreach ([$displayRef,$speakableRef] as $ref) { $response->response->tenant->assertSame($ref->tenant); }
        new Ref($response->response->tenant,$id);
        if (min($sequence,$displayBytes,$speakableBytes)<1 || max($sequence,$displayBytes,$speakableBytes)>9007199254740991
            || !array_is_list($boundaries) || count($boundaries)<2 || count($boundaries)>4096
            || $boundaries[0]!==0 || $boundaries[count($boundaries)-1]!==$speakableBytes) {
            throw new \InvalidArgumentException('Bounded complete segment and aligned offsets required');
        }
        $last=-1;$normalized=[];
        foreach ($boundaries as $offset) {
            if (!is_int($offset) || $offset<=$last || $offset>$speakableBytes) { throw new \InvalidArgumentException('Monotonic aligned offsets required'); }
            $last=$offset;$normalized[]=$offset;
        }
        $this->boundaries=$normalized;
        $this->key = new Ref($response->response->tenant,'voice-segment:'.substr($hasher->hash($response->response->tenant,
            'voice-segment-key',[$response->key->value,$id])->value,8));
        $this->hash=$hasher->hash($response->response->tenant,'voice-segment',$this->manifest());
    }
    /** @return array<string,mixed> */
    public function manifest(): array
    {
        return ['response_key'=>$this->response->key->value,'response_hash'=>$this->response->hash->value,'segment_id'=>$this->id,
            'sequence'=>$this->sequence,'display_ref'=>$this->displayRef->toArray(),'display_hash'=>$this->displayHash->value,
            'display_bytes'=>$this->displayBytes,'speakable_ref'=>$this->speakableRef->toArray(),
            'speakable_hash'=>$this->speakableHash->value,'speakable_bytes'=>$this->speakableBytes,'boundaries'=>$this->boundaries];
    }
}
