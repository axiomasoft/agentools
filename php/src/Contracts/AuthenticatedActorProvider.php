<?php

declare(strict_types=1);

namespace AgenTools\Contracts;

use AgenTools\Confirmation\AuthenticatedActor;

interface AuthenticatedActorProvider
{
    public function currentActor(): AuthenticatedActor;
}
