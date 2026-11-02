<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Dependencies;

use AgenToolsPlatform\Knowledge\Integrity;

final readonly class DeletionRequest
{
    public string $id;
    public function __construct(public Node $node, public string $providerRef, public string $support,
        public ?string $knownExpiry)
    {
        Integrity::text($providerRef);
        if (!in_array($support,['supported','unsupported','unknown'],true)) { throw new \InvalidArgumentException('Explicit delete capability required'); }
        if ($knownExpiry !== null) {
            if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?Z\z/D',$knownExpiry,$parts) !== 1
                || !checkdate((int)$parts[2],(int)$parts[3],(int)$parts[1])
                || (int)$parts[4]>23 || (int)$parts[5]>59 || (int)$parts[6]>59) {
                throw new \InvalidArgumentException('Valid UTC expiry required');
            }
        }
        $this->id = Integrity::digest([$node->tenant,$node->key(),$providerRef]);
    }
    public function hash(): string { return Integrity::digest([$this->node,$this->providerRef,$this->support,$this->knownExpiry]); }
}
