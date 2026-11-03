<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Core\Models\Cache;

use AgenToolsPlatform\Core\Common\{Ref,UtcInstant};
use AgenToolsPlatform\Core\Models\Time;

/** Evidence supplied by current trusted host policy, never derived from cached text. */
final readonly class Rights
{
    public function __construct(public Ref $evidence,public UtcInstant $expiresAt,public bool $storageAllowed,
        public bool $warmAllowed,public bool $stableNonpersonalMaterial) {}
    public function assertFor(Key $key,UtcInstant $now,bool $storage=false,bool $warm=false): void
    {
        $key->id->tenant->assertSame($this->evidence->tenant);
        if (Time::compare($now,$this->expiresAt)>=0 || ($storage && !$this->storageAllowed)
            || ($warm && (!$this->warmAllowed || !$this->stableNonpersonalMaterial || $key->dimensions['personal']))) {
            throw new \LogicException('Current cache rights denied');
        }
    }
}
