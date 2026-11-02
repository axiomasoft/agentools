<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Retrieval;

use AgenToolsPlatform\Knowledge\{EmbeddingSpace, Release};

interface SearchIndex
{
    /** null for lexical; a complete exact space for semantic. Never resolve latest. */
    public function space(): ?EmbeddingSpace;

    /**
     * Implementations must constrain the underlying query to these already authorized exact releases.
     * @param array<string,Release> $releases keyed by ReleasePin::key()
     * @param null|list<float> $vector
     * @return list<Candidate>
     */
    public function search(Query $query, array $releases, ?array $vector = null): array;
}
