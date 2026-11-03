<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Laravel\Operations;

interface Authority
{
    /** Current tenant/operator/target ACL + emergency policy. Enforce a bounded rate even for reads.
     * Must not derive authority from the command IDs; called again immediately before dispatch/disclosure.
     */
    public function authorize(Command $command): void;
}
