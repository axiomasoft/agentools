<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Core\Models\Routing;
use AgenToolsPlatform\Core\Common\Ref;

interface EffectLookup
{
    /** Read-only authoritative lookup with the original owner operation key. No effect execution. */
    public function status(Ref $operation): EffectResolution;
}
