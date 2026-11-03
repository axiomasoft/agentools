<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Core\Models\Cache;

final readonly class WarmState
{
    public function __construct(public WarmIntent $intent,public string $status,public string $usageJson,
        public bool $fresh=false,public ?Descriptor $descriptor=null)
    {
        if (!in_array($status,['pending','sent','known','unknown','accounted'],true)) { throw new \InvalidArgumentException('Explicit warm checkpoint required'); }
    }
}
