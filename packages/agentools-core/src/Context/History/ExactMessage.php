<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context\History;

use AgenToolsPlatform\Core\Common\{CanonicalHasher,Ref,UtcInstant};
use AgenToolsPlatform\Core\Context\Classification;

/** Transient owner-provided exact message, never persisted as transcript by this package. */
final readonly class ExactMessage
{
    public function __construct(public MessageRef $source,public Ref $owner,public string $text,
        public Classification $classification,public UtcInstant $expiresAt,CanonicalHasher $hasher)
    {
        $source->ref->tenant->assertSame($owner->tenant);
        $source->hash->assertSame($hasher->hash($source->ref->tenant,'history-message',$text));
    }
}
