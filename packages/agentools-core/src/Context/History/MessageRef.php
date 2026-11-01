<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context\History;

use AgenToolsPlatform\Core\Common\{PayloadHash,Ref};

final readonly class MessageRef
{
    public function __construct(public Ref $ref,public Ref $branch,public int $turn,public string $version,public PayloadHash $hash)
    {
        $ref->tenant->assertSame($branch->tenant); Checks::positive($turn);
        if ($version==='') { throw new \InvalidArgumentException('Exact source version required'); }
    }
    /** @return array<string,mixed> */
    public function toArray(): array { return ['ref'=>$this->ref->toArray(),'branch'=>$this->branch->toArray(),'turn'=>$this->turn,'version'=>$this->version,'hash'=>$this->hash->value]; }
}
