<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Core\Models\Cache;
use AgenToolsPlatform\Core\Common\{PayloadHash,Ref,UtcInstant};

final readonly class WarmIntent
{
    public function __construct(public Ref $id,public Key $key,public PayloadHash $requestHash,
        public UtcInstant $createdAt,public UtcInstant $expiresAt,public int $estimate,public string $unknownUsageJson,public string $admissionJson)
    {
        $key->id->tenant->assertSame($id->tenant); $key->assertRetained($createdAt,$expiresAt);
        if ($estimate<0 || $estimate>9007199254740991) { throw new \InvalidArgumentException('Bounded warm estimate required'); }
    }
}
