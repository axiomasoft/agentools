<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Chatom;

use AgenTools\Chatom\Data\IncomingInput;
use AgenToolsPlatform\Core\Security\ExecutionContext;

/** The host registers this in trusted code; every export rechecks actual owning policy. */
interface OwnerReferenceProducer
{
    /** Immutable code/selection policy pin, without credentials or cached grants. */
    public function pin(): string;

    /** @return list<OwnerReference> */
    public function export(ExecutionContext $context, IncomingInput $input): array;
}
