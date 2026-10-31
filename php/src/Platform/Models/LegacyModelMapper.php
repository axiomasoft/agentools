<?php

declare(strict_types=1);

namespace AgenTools\Platform\Models;

use AgenToolsPlatform\Core\Models\ModelRequest;
use AgenToolsPlatform\Core\Models\ModelResponse;

/** Host maps its existing arbitrary array vocabulary and legacy exception semantics. */
interface LegacyModelMapper
{
    /** @param array<string, mixed> $input */
    public function request(array $input): ModelRequest;
    /** @return array<string, mixed> Must reject partial/unknown/error unless the legacy API represents it. */
    public function response(ModelResponse $response): array;
}
