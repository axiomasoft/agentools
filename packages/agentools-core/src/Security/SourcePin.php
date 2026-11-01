<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Security;

use AgenToolsPlatform\Core\Common\{PayloadHash,Ref,UtcInstant};

/** Immutable identity, never an authorization grant. Null expiry requires current owner validation. */
final readonly class SourcePin
{
    /** @param array<array-key,Ref> $messageIds */
    public function __construct(public Ref $source,public Ref $content,public string $version,public PayloadHash $hash,public ?UtcInstant $expiresAt,
        public ?Ref $derived=null,public array $messageIds=[])
    { $source->tenant->assertSame($content->tenant);if ($derived!==null) { $source->tenant->assertSame($derived->tenant); }
        if (!array_is_list($messageIds)) { throw new \InvalidArgumentException('Exact source IDs must be a list'); }foreach ($messageIds as $id) { $source->tenant->assertSame($id->tenant); }
        if ($version==='') { throw new \InvalidArgumentException('Pinned source version required'); } }
    /** @return array<string,mixed> */
    public function toArray(): array { return ['source_ref'=>$this->source->toArray(),'content_ref'=>$this->content->toArray(),'version'=>$this->version,'hash'=>$this->hash->value,'expires_at'=>$this->expiresAt?->value,'derived_ref'=>$this->derived?->toArray(),'message_ids'=>array_map(static fn(Ref $r): array=>$r->toArray(),$this->messageIds)]; }
}
