<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Retrieval\Adapters;

use AgenToolsPlatform\Knowledge\Retrieval\{Cache, Selection};

/** Optional bounded process-local cache of refs only. Hosts clear it at worker/job reset. */
final class ArrayCache implements Cache
{
    /** @var array<string,Selection> */
    private array $entries = [];
    public function __construct(private readonly int $capacity = 64)
    {
        if ($capacity < 1 || $capacity > 1024) { throw new \InvalidArgumentException('Bounded cache capacity required'); }
    }
    public function get(string $key): ?Selection { return $this->entries[$key] ?? null; }
    public function put(string $key, Selection $selection): void
    {
        unset($this->entries[$key]);
        if (count($this->entries) >= $this->capacity) { array_shift($this->entries); }
        $this->entries[$key] = $selection;
    }
    public function clear(): void { $this->entries = []; }
}
