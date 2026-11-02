<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Usage;

/** Independent facts. No method adds all categories into a universal token total. */
final readonly class Units
{
    /** @param array<string,int|string|null> $facts */
    private function __construct(private array $facts) {}
    /** @param array<string,mixed> $facts */
    public static function fromArray(array $facts): self
    {
        $keys = ['request_count','input_uncached_tokens','input_cache_read_tokens','input_cache_write_tokens',
            'output_tokens','reasoning_tokens','audio_input_units','audio_output_units','audio_unit'];
        Values::keys($facts,$keys);
        $result = [];
        foreach ($keys as $key) {
            $v = $facts[$key];
            $result[$key] = $v === null ? null : (in_array($key,['audio_input_units','audio_output_units'],true)
                ? Values::decimal($v) : ($key === 'audio_unit' ? Values::text($v) : Values::integer($v)));
        }
        if (($result['audio_input_units'] !== null || $result['audio_output_units'] !== null) && $result['audio_unit'] === null) {
            throw new \InvalidArgumentException('Audio quantities require an explicit unit');
        }
        if ($result['audio_input_units'] === null && $result['audio_output_units'] === null && $result['audio_unit'] !== null) {
            throw new \InvalidArgumentException('Absent audio has no invented unit');
        }
        return new \AgenToolsPlatform\Core\Usage\Units($result);
    }
    /** @return array<string,int|string|null> */
    public function toArray(): array { return $this->facts; }
    public static function unknown(?int $requestCount): self
    { return self::fromArray(['request_count'=>$requestCount,'input_uncached_tokens'=>null,'input_cache_read_tokens'=>null,
        'input_cache_write_tokens'=>null,'output_tokens'=>null,'reasoning_tokens'=>null,'audio_input_units'=>null,'audio_output_units'=>null,'audio_unit'=>null]); }
}
