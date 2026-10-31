<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Security;

use AgenToolsPlatform\Core\Common\SessionId;

/** Installed by trusted server composition, never hydrated from user/model JSON. */
interface HostAuthorization
{
    /** Authenticate, authorize this tenant/session/purpose, and check current emergency/consent policy. */
    public function authorize(SessionId $session, string $purpose): HostIdentity;
}
