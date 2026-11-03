<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Core\Models\Cache;

final readonly class Resolution
{
    public function __construct(public Prepared $prepared,public ?Descriptor $hit,public string $reason)
    { if ($hit!==null) { $prepared->key->assertSame($hit->key); } }
}
