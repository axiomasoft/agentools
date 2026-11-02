<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Usage;

/** Declared adapter contract; field names are a whitelist, not raw accounting payload retention. */
final readonly class ProviderMapping
{
    /** @param array<string,string> $fields */
    public function __construct(public string $ref, public array $fields, public bool $inputIncludesCacheRead,
        public string $cacheWriteRelation, public string $reasoningRelation)
    {
        Values::text($ref);
        if (!in_array($cacheWriteRelation,['subset_of_uncached','separate'],true) || !in_array($reasoningRelation,['subset_of_output','separate'],true)) { throw new \InvalidArgumentException('Explicit overlap mapping required'); }
        foreach ($fields as $unit=>$field) {
            if (!in_array($unit,['input','cache_read','cache_write','output','reasoning','audio_input','audio_output','audio_unit','provider_amount'],true)) { throw new \InvalidArgumentException('Unknown mapping category'); }
            Values::text($field);
        }
    }
}
