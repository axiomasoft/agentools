<?php

declare(strict_types=1);

namespace AgenTools\Effects;

use AgenTools\Action\EffectRecord;

/** Port for the product-owned external effect invocation. */
interface EffectApplier
{
    public function apply(EffectRecord $effect): void;
}
