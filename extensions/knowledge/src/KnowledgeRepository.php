<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge;

interface KnowledgeRepository
{
    /** Same tenant/corpus/release ID with differing hash conflicts. Correction atomically invalidates previous version. */
    public function publish(Scope $scope, Release $release, ?Material $previous = null): Release;
    /** Current rights and local invalidation override all pins/cache hits. */
    public function get(Scope $scope, string $corpus, string $id): ?Release;
    public function invalidate(Scope $scope, Material $material, string $reason): void;
    /** Exact-version dependency for downstream bundle/snapshot/cache adapters. */
    public function link(Scope $scope, Release $release, string $kind, string $ref): void;
    public function dependencyUsable(Scope $scope, Release $release, string $kind, string $ref): bool;
}
