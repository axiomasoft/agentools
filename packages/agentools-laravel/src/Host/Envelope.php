<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Host;

use AgenToolsPlatform\Core\Common\PayloadHash;
use AgenToolsPlatform\Core\Context\ContextBlock;
use AgenToolsPlatform\Core\Security\TrustedInput;
use AgenToolsPlatform\Laravel\Managed\Input;

/** Code-only immutable projection. Boundary rehydrates authority before use. */
final readonly class Envelope
{
    /** @param list<ContextBlock> $slices */
    public function __construct(public Input $handle, public TrustedInput $input, public ContextState $state,
        public array $slices, public PayloadHash $fingerprint) {}
}
