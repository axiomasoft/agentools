<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context;

use AgenToolsPlatform\Core\Common\Ref;

/** Adapter renders the exact API request representation that will be counted and sent. */
interface Renderer
{
    public function deployment(): Ref;
    public function version(): string;
    /** Documents provider-specific layout changes relative to baseline sections. */
    public function layout(): string;
    /** @param list<ContextBlock> $blocks */
    public function render(array $blocks): string;
}
