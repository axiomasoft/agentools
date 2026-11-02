<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Retrieval\Adapters;

use AgenToolsPlatform\Knowledge\EmbeddingSpace;
use AgenToolsPlatform\Knowledge\Retrieval\{Candidate, Failure, Query, SearchIndex, Vector, VectorRecord};

/** Host-supplied, versioned vector records. One space per immutable adapter instance. */
final readonly class SemanticIndex implements SearchIndex
{
    /** @param list<VectorRecord> $records */
    public function __construct(private EmbeddingSpace $embeddingSpace, private array $records)
    {
        if (!array_is_list($records)) { throw new \InvalidArgumentException('Vector record list required'); }
    }

    public function space(): EmbeddingSpace { return $this->embeddingSpace; }

    public function search(Query $query, array $releases, ?array $vector = null): array
    {
        if ($vector === null || $query->space === null || $query->space->key() !== $this->embeddingSpace->key()) {
            throw new Failure('incompatible', 'SPACE_MISMATCH');
        }
        Vector::validate($vector, $this->embeddingSpace->dimensions);
        $allowed = [];
        foreach ($query->releases as $pin) {
            if (isset($releases[$pin->key()])) { $allowed[$pin->hash] = $pin; }
        }
        $hits = [];
        foreach ($this->records as $record) {
            // Rights/version/tenant filtering precedes scoring, even for corrupt unrelated vectors.
            $pin = $allowed[$record->releaseHash] ?? null;
            if ($pin === null || $record->tenant !== $query->scope->tenant) { continue; }
            $release = $releases[$pin->key()];
            if ($release->space === null || $release->space->key() !== $this->embeddingSpace->key()) { throw new Failure('incompatible', 'SPACE_MISMATCH'); }
            Vector::validate($record->vector, $this->embeddingSpace->dimensions);
            foreach ($release->extraction->artifacts as $artifact) {
                if ($artifact->kind === 'fragment' && $artifact->id === $record->artifact) {
                    $hits[] = new Candidate($pin, $artifact->id, $artifact->start, $artifact->end, Vector::cosine($vector, $record->vector));
                }
            }
        }
        usort($hits, static fn(Candidate $a, Candidate $b): int => ($b->score <=> $a->score) ?: strcmp($a->key(), $b->key()));
        return array_slice($hits, 0, 512);
    }
}
