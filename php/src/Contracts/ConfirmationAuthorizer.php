<?php

declare(strict_types=1);

namespace AgenTools\Contracts;

use AgenTools\Confirmation\AuthorizationDecision;
use AgenTools\Confirmation\AuthorizationRequest;

interface ConfirmationAuthorizer
{
    public function authorize(AuthorizationRequest $request): AuthorizationDecision;
}
