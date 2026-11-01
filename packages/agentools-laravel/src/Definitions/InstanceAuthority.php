<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Definitions;

use AgenToolsPlatform\Core\Common\InstanceId;
use AgenToolsPlatform\Core\Profiles\BundleRef;
use AgenToolsPlatform\Core\Security\ExecutionContext;

interface InstanceAuthority
{
    /** Check CURRENT host permission for read/create/assign/draft/pin; no SQL transaction held. */
    public function authorize(ExecutionContext $context,InstanceId $instance,string $operation): void;
    /** Host verifies immutable published bundle hash, tenant ownership and definition compatibility. */
    public function bundle(ExecutionContext $context,Definition $definition,BundleRef $bundle): void;
}
