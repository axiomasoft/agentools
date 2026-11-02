<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models\Routing;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, UtcInstant};
use AgenToolsPlatform\Core\Models\{Support, Time};

/** Qualification is a strict filter. Host supplied ranking never grants eligibility. */
final readonly class Router
{
    /** @param array<string,string> $knownRoutes */
    public function __construct(private Catalog $catalog, private HostPolicy $host, private CanonicalHasher $hasher,
        private array $knownRoutes = []) {}

    /** @param list<string> $excluded */
    public function route(RouteRequest $request, UtcInstant $now, array $excluded = []): RouteDecision
    {
        $view = $this->catalog->view($request->subject->tenant); $eligible = []; $rejected = [];
        foreach ($view->deployments as $state) {
            $entry = $state->entry; $id = $entry->deployment->ref->value;
            $reasons = $this->reasons($request, $state, $view, $now, false);
            if (in_array($id, $excluded, true)) { $reasons[] = Reason::Outage; }
            if ($reasons !== []) { $rejected[$id] = $reasons; } else { $eligible[] = $entry; }
        }
        $preferred = $request->knownRoute === null ? null : ($this->knownRoutes[$request->knownRoute] ?? null);
        usort($eligible, static function (CatalogEntry $a, CatalogEntry $b) use ($preferred, $now): int {
            return (($b->deployment->ref->value === $preferred) <=> ($a->deployment->ref->value === $preferred))
                ?: ($b->quality <=> $a->quality) ?: (($a->latencyMs ?? PHP_INT_MAX) <=> ($b->latencyMs ?? PHP_INT_MAX))
                ?: (($a->costAt($now) ?? PHP_INT_MAX) <=> ($b->costAt($now) ?? PHP_INT_MAX))
                ?: strcmp($a->deployment->ref->value, $b->deployment->ref->value);
        });
        $selected = $eligible[0] ?? null;
        foreach (array_slice($eligible, 1) as $entry) { $rejected[$entry->deployment->ref->value] = [Reason::Rank]; }
        ksort($rejected);
        return new \AgenToolsPlatform\Core\Models\Routing\RouteDecision($selected, $rejected);
    }

    /**
     * Repeated at finalization and immediately before network IO, including pinned sessions.
     * @return list<Reason>
     */
    public function revalidate(RouteRequest $request, CatalogEntry $pinned, UtcInstant $now, bool $alreadyAdmitted = false): array
    {
        $view = $this->catalog->view($request->subject->tenant);
        foreach ($view->deployments as $state) {
            if ($state->entry->deployment->ref->value === $pinned->deployment->ref->value) {
                if ($state->entry->hash->value !== $pinned->hash->value) { return [Reason::Binding]; }
                return $this->reasons($request, $state, $view, $now, $alreadyAdmitted);
            }
        }
        return [Reason::Binding];
    }

    /** @return list<Reason> */
    private function reasons(RouteRequest $r, DeploymentState $state, CatalogView $view, UtcInstant $now, bool $admitted): array
    {
        $e = $state->entry; $d = $e->deployment; $reasons = [];
        if ($d->ref->tenant->value !== $r->subject->tenant->value) { return [Reason::Tenant]; }
        if (!in_array($d->region, $r->regions, true)) { $reasons[] = Reason::Region; }
        if (array_diff($r->dataClasses, $e->dataClasses) !== []) { $reasons[] = Reason::Data; }
        if (!$e->terms->permits($now) || !$e->modelEvidence->permits($now)) { $reasons[] = Reason::Legal; }
        foreach ($r->capabilities as $capability) {
            if (!$d->supports($capability, $now)) { $reasons[] = Reason::Capability; break; }
        }
        if ($state->admin !== AdminState::Enabled && !($admitted && $state->admin === AdminState::Draining)) { $reasons[] = Reason::Admin; }
        if ($state->health === Health::Unavailable || $state->health === Health::Unknown) { $reasons[] = Reason::Health; }
        foreach ($view->emergency as $rule) {
            if ($rule->matches($r, $e)) { $reasons[] = Reason::Emergency; break; }
        }
        $host = $this->host->check($r, $e, $now);
        if ($host->legal !== Support::Supported) { $reasons[] = Reason::Legal; }
        if ($host->permission !== Support::Supported) { $reasons[] = Reason::Host; }
        if ($host->quota !== Support::Supported) { $reasons[] = Reason::Quota; }
        $hash = QualificationTuple::hash($r, $e, $this->hasher); $latest = null;
        foreach ($state->qualifications as $q) {
            if ($q->ref->tenant->value !== $r->subject->tenant->value || $q->tupleHash->value !== $hash->value
                || Time::compare($q->issuedAt, $now) > 0) { continue; }
            if ($latest === null || Time::compare($q->issuedAt, $latest->issuedAt) > 0
                || (Time::compare($q->issuedAt, $latest->issuedAt) === 0 && !$q->permits($hash, $now))) { $latest = $q; }
        }
        if ($latest === null || !$latest->permits($hash, $now)) { $reasons[] = Reason::Qualification; }
        return array_values(array_unique($reasons, SORT_REGULAR));
    }
}
