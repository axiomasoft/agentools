<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Core\Models\Cache;
use AgenToolsPlatform\Core\Common\{Ref,UtcInstant};
use AgenToolsPlatform\Core\Models\Time;

final readonly class Descriptor
{
    public function __construct(public Key $key,public Ref $artifact,public UtcInstant $createdAt,
        public UtcInstant $expiresAt,public UtcInstant $lastUsed,public Ref $rightsEvidence,public string $state='active')
    {
        $key->id->tenant->assertSame($artifact->tenant); $key->id->tenant->assertSame($rightsEvidence->tenant);
        $key->assertRetained($createdAt,$expiresAt);
        if (Time::compare($lastUsed,$createdAt)<0 || !in_array($state,['active','invalidated'],true)) {
            throw new \InvalidArgumentException('Invalid cache descriptor');
        }
    }
    public function usable(UtcInstant $now): bool
    { return $this->state==='active' && Time::compare($this->createdAt,$now)<=0 && Time::compare($now,$this->expiresAt)<0; }
}
