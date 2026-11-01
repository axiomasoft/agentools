<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Runtime;

use AgenToolsPlatform\Core\Models\{Deployment,ModelRequest};

/** Same versioned concrete API representation as the Context renderer, including tools/model parameters. */
interface RequestRenderer { public function version(): string;public function renderRequest(ModelRequest $request,Deployment $deployment): string; }
