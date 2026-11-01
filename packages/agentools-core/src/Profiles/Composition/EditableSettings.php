<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Composition;

/** Only declared style fields. Security and tool permissions are not editable settings. */
final readonly class EditableSettings
{
    public function __construct(public bool $tone, public bool $displayName, public int $maxDisplayNameBytes = 80)
    {
        if ($maxDisplayNameBytes < 1 || $maxDisplayNameBytes > 80) { throw new \InvalidArgumentException('Bounded editable name limit required'); }
    }
    /**
     * @return array<string, bool|int>
     */
    public function toArray(): array { return ['tone'=>$this->tone, 'display_name'=>$this->displayName, 'max_display_name_bytes'=>$this->maxDisplayNameBytes]; }
}
