<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models\Routing;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode};
use AgenToolsPlatform\Core\Models\{Deployment, ModelRequest, ModelResponse, ModelTransport, TransportPreflight};

/** Compose inside the existing guarded transport; this adapter adds no invocation or Action loop. */
final readonly class QualifiedTransport implements \AgenToolsPlatform\Core\Models\ModelTransport
{
    public function __construct(private ModelTransport $inner, private Router $router, private RouteRequest $route,
        private CatalogEntry $selected, private Clock $clock, private bool $admitted = false) {}
    public function generate(ModelRequest $request, Deployment $deployment): ModelResponse
    { $this->check($request, $deployment, false); return $this->inner->generate($request, $deployment); }
    public function stream(ModelRequest $request, Deployment $deployment): iterable
    { $this->check($request, $deployment, true); yield from $this->inner->stream($request, $deployment); }
    private function check(ModelRequest $request, Deployment $deployment, bool $stream): void
    {
        $now = $this->clock->now(); $pin = $this->selected->deployment;
        $request->invocationId->tenant->assertSame($this->route->subject->tenant);
        $pin->ref->assertSame($deployment->ref); $this->selected->credential->assertSame($request->credentialRef);
        if ([$pin->provider,$pin->account,$pin->model,$pin->region] !== [$deployment->provider,$deployment->account,$deployment->model,$deployment->region]
            || $this->router->revalidate($this->route, $this->selected, $now, $this->admitted) !== []) {
            throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::AccessDenied);
        }
        foreach ($request->requiredCapabilities($stream) as $capability) {
            if (!in_array($capability, $this->route->capabilities, true)) { throw new \AgenToolsPlatform\Core\Common\ContractViolation(ErrorCode::CapabilityUnavailable); }
        }
        TransportPreflight::check($request, $pin, $now, $stream);
    }
}
