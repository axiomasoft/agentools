<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Context;

use AgenToolsPlatform\Core\Context\ContextSnapshot;
use AgenToolsPlatform\Core\Security\ExecutionContext;

/** Required current host permission/retention/emergency policy; historical pins confer no rights. */
interface Authority
{
    public function authorize(ExecutionContext $context, ContextSnapshot $snapshot, string $operation): void;
}
