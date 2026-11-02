<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge;

interface EmbeddingTransport
{
    /**
     * @param non-empty-list<string> $texts
     * @return non-empty-list<list<float>>
     * Explicit opt-in only; no provider call is made by ingest/release.
     */
    public function embed(Scope $scope, EmbeddingSpace $space, array $texts): array;
}
