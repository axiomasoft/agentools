<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Core\Models\Qualification;
use AgenToolsPlatform\Core\Common\{CanonicalHasher, PayloadHash, TenantId};
use AgenToolsPlatform\Core\Models\Routing\{Catalog, CatalogView, Clock, DeploymentState, Qualification, RouteRequest};

/** Compose with the existing Router/QualifiedTransport: every view reads current state. */
final readonly class SnapshotCatalog implements \AgenToolsPlatform\Core\Models\Routing\Catalog
{
    public function __construct(private Catalog $inner, private SnapshotStore $snapshots, private RequirementSource $requirements,
        private RouteRequest $request, private CanonicalHasher $hasher, private Clock $clock) {}
    public function view(TenantId $tenant): CatalogView
    {
        $tenant->assertSame($this->request->subject->tenant); $view = $this->inner->view($tenant); $states = [];
        foreach ($view->deployments as $state) {
            $qs = []; $requirements = $this->requirements->current($this->request,$state->entry);
            if ($requirements !== null) {
                $tuple = Tuple::forRoute($this->request,$state->entry,$requirements,$this->hasher);
                $snapshot = $this->snapshots->latest($tenant,$tuple->hash($this->hasher),$this->clock->now());
                if ($snapshot !== null && $snapshot->permits($tuple,$requirements->evidencePolicy,$this->clock->now(),$this->hasher)) {
                    $qs[] = new \AgenToolsPlatform\Core\Models\Routing\Qualification($snapshot->ref,new \AgenToolsPlatform\Core\Common\PayloadHash($tuple->components['routing_hash']),$snapshot->decision,
                        $snapshot->issuedAt,$snapshot->expiresAt,$snapshot->evidence->ref);
                }
            }
            // Old catalog certificates cannot bypass the additional policy/evidence filter.
            $states[] = new \AgenToolsPlatform\Core\Models\Routing\DeploymentState($state->entry,$state->admin,$state->health,$state->version,$qs);
        }
        return new \AgenToolsPlatform\Core\Models\Routing\CatalogView($states,$view->emergency);
    }
}
