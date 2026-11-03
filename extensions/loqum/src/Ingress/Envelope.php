<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Loqum\Ingress;

use AgenToolsPlatform\Core\Common\PayloadHash;
use AgenToolsPlatform\Core\Security\TrustedInput;

/** Same TrustedInput used by text/compiler/intake. This value does not execute a model or Action. */
final readonly class Envelope
{
    public function __construct(public string $handle, public TrustedInput $input,
        public MediaState $media, public PayloadHash $fingerprint) {}
}
