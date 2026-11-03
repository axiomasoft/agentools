<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Loqum\Delivery;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, Ref};

/** Stable suffix operation; reconnect does not change key, segment or payload hash. */
final readonly class Attempt
{
    public Ref $key;
    public function __construct(public Segment $segment, public int $offset, public int $epoch, CanonicalHasher $hasher)
    {
        if ($epoch<1 || $epoch>9007199254740991 || !in_array($offset,$segment->boundaries,true) || $offset===$segment->speakableBytes) {
            throw new \InvalidArgumentException('Undelivered aligned suffix and current media epoch required');
        }
        $this->key=new Ref($segment->key->tenant,'voice-play:'.substr($hasher->hash($segment->key->tenant,
            'voice-play-key',[$segment->key->value,$offset])->value,8));
    }
}
