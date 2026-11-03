<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Loqum\Delivery;

use AgenToolsPlatform\Core\Common\Ref;

final readonly class DisplayReceipt
{
    public function __construct(public Ref $ref, public Ref $segment, public int $mediaEpoch, public int $offset)
    {
        $ref->tenant->assertSame($segment->tenant);
        if ($mediaEpoch<1 || $offset<0 || max($mediaEpoch,$offset)>9007199254740991) { throw new \InvalidArgumentException('Bounded display evidence required'); }
    }
}
