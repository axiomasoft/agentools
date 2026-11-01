<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Actions\Cancellation;

/** Authenticate operator and verify current target ACL, emergency policy, reason and evidence. Throws on denial. */
interface Authority
{
    public function authorize(Operation $operation): void;
}
