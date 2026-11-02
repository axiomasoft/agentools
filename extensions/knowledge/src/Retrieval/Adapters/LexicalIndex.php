<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Retrieval\Adapters;

use AgenToolsPlatform\Knowledge\EmbeddingSpace;
use AgenToolsPlatform\Knowledge\Retrieval\{Candidate, Query, SearchIndex, Selector};

/** Local term-overlap adapter over the already filtered release set. No provider or index-wide query. */
final class LexicalIndex implements SearchIndex
{
    public function space(): ?EmbeddingSpace { return null; }

    public function search(Query $query, array $releases, ?array $vector = null): array
    {
        $hits = []; $terms = Selector::terms($query->normalized);
        foreach ($query->releases as $pin) {
            $release = $releases[$pin->key()] ?? null;
            if ($release === null) { continue; }
            foreach ($release->extraction->artifacts as $artifact) {
                if ($artifact->kind !== 'fragment') { continue; }
                $score = 0;
                foreach ($terms as $term) { if (Selector::matches($artifact->text, $term)) { ++$score; } }
                if ($score > 0) { $hits[] = new Candidate($pin, $artifact->id, $artifact->start, $artifact->end, $score / max(1, count($terms))); }
            }
        }
        usort($hits, static fn(Candidate $a, Candidate $b): int => ($b->score <=> $a->score) ?: strcmp($a->key(), $b->key()));
        return array_slice($hits, 0, 512);
    }
}
