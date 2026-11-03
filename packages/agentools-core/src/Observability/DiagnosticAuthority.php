<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Core\Observability;

use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Security\ExecutionContext;

interface DiagnosticAuthority
{
    /** Separate current permission/target ACL/purpose/emergency check. Inspect permission is insufficient. */
    public function authorize(ExecutionContext $context,Ref $target,DiagnosticPermission $permission): Ref;
    public function reset(): void;
}
