<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Loqum\Ingress;

use AgenToolsPlatform\Core\Security\{ExecutionContext, HostAuthorization};

/** Trusted backend injection only. No provider credentials or frontend grants enter this port. */
interface Owner extends HostAuthorization
{
    public function request(string $handle): Request;
    public function media(ExecutionContext $context): MediaState;
    /** Current consent/emergency/recording rules, separately for voice and text fallback. Deny unknown. */
    public function assertPermitted(ExecutionContext $context, Request $request): void;
}
