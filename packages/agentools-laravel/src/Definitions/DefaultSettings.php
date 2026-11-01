<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Definitions;

/** Code defaults are distinct from editable draft values and hard restrictions. */
final readonly class DefaultSettings
{
    public function __construct(public Tone $tone=Tone::Neutral, public string $displayName='Assistant')
    {
        if ($displayName==='' || strlen($displayName)>256) { throw new \InvalidArgumentException('Bounded display name required'); }
    }
    /** @return array{tone:string,display_name:string} */
    public function toArray(): array { return ['tone'=>$this->tone->value,'display_name'=>$this->displayName]; }
}
