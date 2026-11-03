<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Core\Models\Cache;
use AgenToolsPlatform\Core\Common\{Ref,UtcInstant};

final readonly class Deletion
{
    public function __construct(public Ref $id,public Descriptor $descriptor,public string $status,public ?Ref $receipt=null)
    {
        $id->tenant->assertSame($descriptor->key->id->tenant);
        if ($receipt!==null) { $id->tenant->assertSame($receipt->tenant); }
        if (!in_array($status,['pending','unknown','unsupported','deleted','expired','local_only'],true)
            || ($status==='deleted' && $receipt===null)) { throw new \InvalidArgumentException('Honest deletion outcome required'); }
    }
}
