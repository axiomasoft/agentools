<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Security;

use AgenToolsPlatform\Core\Common\Ref;

final readonly class GeneratedChunk
{
    public function __construct(public Ref $invocation,public Ref $response,public int $sequence,public string $bytes,public bool $segmentEnd,public bool $responseEnd)
    {
        $invocation->tenant->assertSame($response->tenant);
        if ($sequence<1 || $sequence>9007199254740991 || ($responseEnd && !$segmentEnd)) { throw new \InvalidArgumentException('Ordered generation and complete terminal segment required'); }
    }
    /** @return array<string,mixed> */
    public function __debugInfo(): array { return ['invocation'=>$this->invocation->value,'response'=>$this->response->value,'sequence'=>$this->sequence,'bytes'=>strlen($this->bytes),'segment_end'=>$this->segmentEnd,'response_end'=>$this->responseEnd]; }
}
