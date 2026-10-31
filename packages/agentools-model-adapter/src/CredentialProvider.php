<?php

declare(strict_types=1);

namespace AgenToolsPlatform\ModelAdapter;

use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Models\Deployment;

interface CredentialProvider
{
    /** Resolve current credentials every exchange. No cached key or user-provided secret. */
    public function resolve(Ref $credentialRef, Deployment $deployment): Credential;
}
