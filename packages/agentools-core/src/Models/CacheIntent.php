<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models;

use AgenToolsPlatform\Core\Common\Ref;

final readonly class CacheIntent
{
    public function __construct(public CacheMode $mode = CacheMode::Bypass, public ?Ref $key = null, public ?int $ttlSeconds = null)
    {
        if (($mode === CacheMode::Bypass && ($key !== null || $ttlSeconds !== null))
            || ($mode !== CacheMode::Bypass && ($key === null || $ttlSeconds === null || $ttlSeconds < 1))) {
            throw new \InvalidArgumentException('Cache intent needs explicit scope and TTL');
        }
    }
}
