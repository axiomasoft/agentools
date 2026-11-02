<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Usage;

use AgenToolsPlatform\Core\Common\CanonicalHasher;

final readonly class UsageNormalizer
{
    public function __construct(private CanonicalHasher $hasher) {}
    /**
     * @param array<string,mixed> $metadata UsageEvent fields excluding source/units/provider_amount.
     * @param array<string,mixed> $raw Whitelisted provider accounting fields only are read.
     */
    public function observe(array $metadata, string $invocation, int $version, Producer $canonical, Producer $observer,
        ProviderMapping $mapping, array $raw, ?bool $outboundProven): Observation
    {
        if ($canonical !== $observer) { return new \AgenToolsPlatform\Core\Usage\Observation(null,true); }
        $certainty = Certainty::from(Values::text($metadata['certainty'] ?? null));
        $count = $outboundProven === true ? 1 : ($outboundProven === false ? 0 : null);
        $facts = Units::unknown($count)->toArray(); $amount = null;
        if ($certainty !== Certainty::Unknown) {
            $input = $this->quantity($mapping,$raw,'input'); $read = $this->quantity($mapping,$raw,'cache_read');
            $write = $this->quantity($mapping,$raw,'cache_write'); $output = $this->quantity($mapping,$raw,'output'); $reasoning = $this->quantity($mapping,$raw,'reasoning');
            if ($mapping->inputIncludesCacheRead) {
                if ($input !== null && $read !== null && $read > $input) { throw new \InvalidArgumentException('Cached subset exceeds input'); }
                $input = $input === null || $read === null ? null : $input - $read;
            }
            if ($mapping->cacheWriteRelation === 'subset_of_uncached' && $input !== null && $write !== null && $write > $input) { throw new \InvalidArgumentException('Cache write subset exceeds uncached'); }
            if ($mapping->reasoningRelation === 'subset_of_output' && $output !== null && $reasoning !== null && $reasoning > $output) { throw new \InvalidArgumentException('Reasoning subset exceeds output'); }
            $facts = ['request_count'=>$count,'input_uncached_tokens'=>$input,'input_cache_read_tokens'=>$read,'input_cache_write_tokens'=>$write,
                'output_tokens'=>$output,'reasoning_tokens'=>$reasoning,'audio_input_units'=>$this->decimal($mapping,$raw,'audio_input'),
                'audio_output_units'=>$this->decimal($mapping,$raw,'audio_output'),'audio_unit'=>Values::optionalText($this->field($mapping,$raw,'audio_unit'))];
            if ($facts['audio_input_units'] === null && $facts['audio_output_units'] === null) { $facts['audio_unit'] = null; }
            $amount = Amount::decode($this->field($mapping,$raw,'provider_amount'))?->toArray();
        }
        $event = UsageEvent::fromArray(array_merge($metadata,['source'=>['kind'=>'agentools.invocation','invocation_id'=>$invocation,'usage_version'=>$version,
            'producer'=>$canonical->value,'mapping_ref'=>$mapping->ref],'units'=>$facts,'provider_amount'=>$amount]),$this->hasher);
        return new \AgenToolsPlatform\Core\Usage\Observation($event,false);
    }
    /** @param array<string,mixed> $raw */
    private function field(ProviderMapping $m, array $raw, string $key): mixed { return isset($m->fields[$key]) ? ($raw[$m->fields[$key]] ?? null) : null; }
    /** @param array<string,mixed> $raw */
    private function quantity(ProviderMapping $m, array $raw, string $key): ?int
    { $v = $this->field($m,$raw,$key); return $v === null ? null : Values::integer($v); }
    /** @param array<string,mixed> $raw */
    private function decimal(ProviderMapping $m, array $raw, string $key): ?string
    { $v = $this->field($m,$raw,$key); return $v === null ? null : Values::decimal($v); }
}
