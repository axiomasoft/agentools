<?php

declare(strict_types=1);

namespace AgenTools\Platform\Models;

use AgenToolsPlatform\Core\Models\Deployment;
use AgenToolsPlatform\Core\Models\ModelRequest;
use AgenToolsPlatform\Core\Models\ModelResponse;

/** No universal LlmPort envelope exists: the host explicitly owns both mappings. */
interface LegacyExchangeMapper
{
    /** Reject every unsupported option/capability before respond (no silent dropping). */
    public function validate(ModelRequest $request, Deployment $deployment): void;
    /** @return array<string, mixed> */
    public function input(ModelRequest $request): array;
    /** @param array<string, mixed> $output */
    public function output(array $output, ModelRequest $request, Deployment $deployment): ModelResponse;
}
