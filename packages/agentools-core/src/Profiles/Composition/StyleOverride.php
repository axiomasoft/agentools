<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Composition;

final readonly class StyleOverride
{
    public function __construct(public ?Tone $tone = null, public ?string $displayName = null)
    {
        if ($displayName !== null) { DefaultSettings::assertName($displayName, 80); }
    }
    /**
     * @return array<string, string|null>
     */
    public function toArray(): array { return ['tone'=>$this->tone?->value, 'display_name'=>$this->displayName]; }
}
