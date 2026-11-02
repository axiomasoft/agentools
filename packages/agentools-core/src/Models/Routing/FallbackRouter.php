<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models\Routing;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, InvocationId, Ref, UtcInstant};

/** Outage planning only: no provider, effects or delivery execution. Unknown effects require owner reconciliation. */
final readonly class FallbackRouter
{
    public function __construct(private Router $router, private CanonicalHasher $hasher) {}
    public function plan(RouteRequest $request, FallbackState $state, UtcInstant $now, bool $explicitSegment = false,
        bool $continuationAllowed = false): FallbackPlan
    {
        $request->subject->tenant->assertSame($state->invocation->tenant); $request->bundle->assertSame($state->bundle);
        if ($state->unknownEffect !== null) { return new \AgenToolsPlatform\Core\Models\Routing\FallbackPlan('reconcile_required', $state); }
        if ($state->disclosed && !($explicitSegment && $continuationAllowed)) {
            return new \AgenToolsPlatform\Core\Models\Routing\FallbackPlan('interrupted', $state);
        }
        $route = $this->router->route($request, $now, array_values(array_unique(array_merge([$state->deployment], $state->attemptedDeployments))));
        if ($route->selected === null) { return new \AgenToolsPlatform\Core\Models\Routing\FallbackPlan($state->disclosed ? 'interrupted' : 'non_ai_text', $state, $route); }
        $hash = $this->hasher->hash($state->invocation->tenant, 'routing-fallback', [
            'origin'=>$state->invocation->value, 'failure'=>$state->failureEvent->value]);
        $id = 'fallback-' . substr($hash->value, 8);
        return new \AgenToolsPlatform\Core\Models\Routing\FallbackPlan($state->disclosed ? 'new_segment' : 'reroute', $state, $route,
            new \AgenToolsPlatform\Core\Common\InvocationId($state->invocation->tenant, $id),
            $state->disclosed ? new \AgenToolsPlatform\Core\Common\Ref($state->invocation->tenant, 'segment-' . substr($hash->value, 8)) : null);
    }
}
