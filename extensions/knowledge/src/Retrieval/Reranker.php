<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Retrieval;

interface Reranker
{
    /** Reorder/subset only. No new references or expanded ranges may be introduced.
     * @param list<Candidate> $candidates
     * @return list<Candidate>
     */
    public function rerank(Query $query, array $candidates): array;
}
