<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context;

use AgenToolsPlatform\Core\Common\Ref;

/** Compatibility text adapter; hosts with API envelope overhead supply their own renderer. */
final readonly class SectionRenderer implements \AgenToolsPlatform\Core\Context\Renderer
{
    public function __construct(private Ref $target) {}
    public function deployment(): Ref { return $this->target; }
    public function version(): string { return 'read-sections/v1'; }
    public function layout(): string { return 'baseline sections; bracketed kind/trust headers and blank separators'; }
    public function render(array $blocks): string
    {
        $parts=[];
        foreach ($blocks as $block) { $parts[]='['.$block->kind.':'.$block->trust."]\n".$block->modelView; }
        return implode("\n\n",$parts);
    }
}
