<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Core\Observability;

use AgenToolsPlatform\Core\Security\EntryPoint;

/** No arbitrary label maps. Unknown numeric values stay null, never inferred zero. */
final readonly class Metric
{
    public function __construct(public Signal $signal,public ?float $value,public Stage $stage,
        public Outcome $outcome,public EntryPoint $entry,public string $category='none',public string $certainty='unknown')
    {
        if (($value!==null && (!is_finite($value) || $value<0))
            || !in_array($category,['none','input','output','cache_read','cache_write','reasoning','warming'],true)
            || !in_array($certainty,['unknown','measured','estimated','known_zero'],true)
            || ($value===null && $certainty!=='unknown') || ($value!==null && $certainty==='unknown') || ($certainty==='known_zero' && $value!==0.0)) {
            throw new \InvalidArgumentException('Bounded metric and explicit certainty required');
        }
    }
    /** @return array<string,string> */
    public function dimensions(): array
    {
        return ['stage'=>$this->stage->value,'outcome'=>$this->outcome->value,'entry'=>$this->entry->value,
            'category'=>$this->category,'certainty'=>$this->certainty];
    }
}
