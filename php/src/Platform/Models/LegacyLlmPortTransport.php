<?php

declare(strict_types=1);

namespace AgenTools\Platform\Models;

use AgenTools\Contracts\LlmPort;
use AgenToolsPlatform\Core\Common\ContractViolation;
use AgenToolsPlatform\Core\Common\ErrorCode;
use AgenToolsPlatform\Core\Common\UtcInstant;
use AgenToolsPlatform\Core\Models\Deployment;
use AgenToolsPlatform\Core\Models\ModelRequest;
use AgenToolsPlatform\Core\Models\ModelResponse;
use AgenToolsPlatform\Core\Models\ModelTransport;
use AgenToolsPlatform\Core\Models\TransportPreflight;

/** Synchronous legacy exchange only: streaming is explicitly unavailable before IO. */
final readonly class LegacyLlmPortTransport implements ModelTransport
{
    /** @param \Closure(): UtcInstant $now Host clock, not an implicit core clock. */
    public function __construct(private LlmPort $legacy, private LegacyExchangeMapper $mapper, private \Closure $now) {}

    public function generate(ModelRequest $request, Deployment $deployment): ModelResponse
    {
        TransportPreflight::check($request, $deployment, ($this->now)());
        $this->mapper->validate($request, $deployment);
        $input = $this->mapper->input($request);
        TransportPreflight::check($request, $deployment, ($this->now)());
        $response = $this->mapper->output($this->legacy->respond($input), $request, $deployment);
        $response->assertFor($request, $deployment);
        return $response;
    }

    public function stream(ModelRequest $request, Deployment $deployment): iterable
    {
        throw new ContractViolation(ErrorCode::CapabilityUnavailable);
    }
}
