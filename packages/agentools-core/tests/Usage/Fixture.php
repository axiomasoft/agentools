<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Tests\Usage;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, Ref, TenantId, UtcInstant};
use AgenToolsPlatform\Core\Usage\{Producer, ProviderMapping, UsageEvent, UsageNormalizer, Values};
use AgenToolsPlatform\Tests\JcsAdapter;

final class Fixture
{
    public CanonicalHasher $hasher;
    public function __construct() { $this->hasher = new CanonicalHasher(new JcsAdapter()); }
    public static function now(): UtcInstant { return new UtcInstant('2026-11-02T10:00:00Z'); }
    public static function ref(string $id): Ref { return new Ref(new TenantId('alpha'),$id); }
    /** @return array<string,mixed> */
    public static function read(string $name): array
    {
        $raw = file_get_contents(dirname(__DIR__,4).'/fixtures/platform/v1/usage/'.$name.'.json');
        if ($raw === false) { throw new \RuntimeException('Missing usage fixture'); }
        return Values::object(json_decode($raw,true,flags:JSON_THROW_ON_ERROR));
    }
    public static function mapping(): ProviderMapping
    {
        $m = self::read('mapping'); $fields = [];
        foreach (Values::object($m['fields']) as $key=>$v) { $fields[$key] = Values::text($v); }
        if (!is_bool($m['input_includes_cache_read'])) { throw new \RuntimeException('Invalid mapping fixture'); }
        return new ProviderMapping(Values::text($m['ref']),$fields,$m['input_includes_cache_read'],Values::text($m['cache_write_relation']),Values::text($m['reasoning_relation']));
    }
    /**
     * @param array<string,mixed> $raw
     * @param array<string,mixed> $meta
     */
    public function event(array $raw = [], array $meta = [], string $invocation = 'inv-1', int $version = 1, ?bool $outbound = true): UsageEvent
    {
        return (new UsageNormalizer($this->hasher))->observe(array_replace(self::read('metadata'),$meta),$invocation,$version,Producer::Transport,Producer::Transport,
            self::mapping(),$raw,$outbound)->event ?? throw new \RuntimeException('Missing canonical event');
    }
}
