<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Plugins;

use AgenToolsPlatform\Core\Common\{PayloadHash, Ref};

/** Immutable host-verified text reference, ALWAYS untrusted context data. Never a permission grant. */
final readonly class Fragment
{
    public string $trust;
    public function __construct(public Ref $ref,public PayloadHash $hash,public int $tokens,public string $provenance)
    {
        if ($tokens<0 || $tokens>1000000 || $provenance==='' || strlen($provenance)>256) { throw new \InvalidArgumentException('Bounded fragment with provenance required'); }
        $this->trust='untrusted_data';
    }
}
