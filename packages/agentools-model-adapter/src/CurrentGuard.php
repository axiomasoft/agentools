<?php

declare(strict_types=1);

namespace AgenToolsPlatform\ModelAdapter;

use AgenToolsPlatform\Core\Models\{ModelRequest, Deployment};

/** Host rechecks current permission, admission, deployment and emergency policy, including during IO. */
interface CurrentGuard
{
    public function assertAllowed(ModelRequest $request, Deployment $deployment): void;
}
