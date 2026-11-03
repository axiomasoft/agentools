<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Laravel\Observability;

use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Laravel\Managed\ProductionBinding;

/** Only host-pinned key refs, scoped current resolver. No model/browser supplied secret handles. */
interface KeyResolver extends ProductionBinding
{
    public function resolve(ExecutionContext $context,Ref $key): string;
    public function reset(): void;
}
