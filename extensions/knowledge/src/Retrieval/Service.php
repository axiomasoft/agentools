<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Retrieval;

use AgenToolsPlatform\Knowledge\{Artifact, Decision, EmbeddingTransport, Integrity, KnowledgeRepository, MaterialAccessPolicy, Release};

/** Buffered retrieval/disclosure. Optional enhancers cannot bypass its mandatory checks. */
final readonly class Service
{
    public function __construct(private KnowledgeRepository $repository, private MaterialAccessPolicy $access,
        private SourceAvailability $sources, private Clock $clock, private SearchIndex $lexical,
        private ?SearchIndex $semantic = null, private ?EmbeddingTransport $embeddings = null,
        private ?Reranker $reranker = null, private ?Cache $cache = null,
        private int $ttl = 30, private string $revision = 'retrieval/v1')
    {
        Integrity::text($revision);
        if ($ttl < 1 || $ttl > 300 || ($semantic !== null && $embeddings === null)) {
            throw new \InvalidArgumentException('Bounded TTL and explicit embedding transport required');
        }
    }

    public function retrieve(Query $query): Selection
    {
        try {
            $releases = $this->permitted($query, 'retrieve');
            if ($releases === []) { return $this->selection($query, 'empty', 'FILTERED', []); }
            $this->spaces($query, $releases);
            $key = Integrity::digest([$query->cacheKey(), $this->revision]);
            $cached = $this->cache?->get($key);
            if ($cached !== null && $cached->expiresAt > $this->clock->now()) {
                if ($cached->status !== 'ready' || !array_is_list($cached->candidates)
                    || $cached->candidates === [] || count($cached->candidates) > $query->maxFragments
                    || $cached->expiresAt > $this->clock->now() + $this->ttl) {
                    throw new Failure('denied', 'INVALID_CACHE');
                }
                foreach ($cached->candidates as $candidate) { $this->resolve($candidate, $releases); }
                return new Selection($query->binding(), 'ready', 'CACHE_HIT', $cached->candidates, $cached->expiresAt);
            }
            $groups = [$this->lexical->search($query, $releases)];
            if ($this->semantic !== null && $query->space !== null && $this->embeddings !== null) {
                $vectors = $this->embeddings->embed($query->scope, $query->space, [$query->normalized]);
                if (!array_is_list($vectors) || count($vectors) !== 1) { throw new Failure('unavailable', 'INVALID_EMBEDDING'); }
                Vector::validate($vectors[0], $query->space->dimensions);
                $this->spaces($query, $releases);
                $groups[] = $this->semantic->search($query, $releases, $vectors[0]);
                $this->spaces($query, $releases);
            }
            $fused = []; $scores = [];
            foreach ($groups as $group) {
                if (!array_is_list($group) || count($group) > 512) { throw new Failure('unavailable', 'INDEX_LIMIT'); }
                usort($group, static fn(Candidate $a, Candidate $b): int => ($b->score <=> $a->score) ?: strcmp($a->key(), $b->key()));
                $seen = [];
                foreach ($group as $rank => $candidate) {
                    $this->resolve($candidate, $releases); $id = $candidate->key();
                    if (isset($seen[$id])) { continue; }
                    $seen[$id] = true; $fused[$id] = $candidate;
                    $scores[$id] = ($scores[$id] ?? 0.0) + 1.0 / (60 + $rank + 1);
                }
            }
            $ranked = array_values($fused);
            usort($ranked, static fn(Candidate $a, Candidate $b): int => ($scores[$b->key()] <=> $scores[$a->key()]) ?: strcmp($a->key(), $b->key()));
            if ($this->reranker !== null) {
                $reranked = $this->reranker->rerank($query, $ranked); $seen = [];
                if (!array_is_list($reranked)) { throw new Failure('denied', 'INVALID_RERANK'); }
                foreach ($reranked as $candidate) {
                    $id = $candidate->key();
                    if (!isset($fused[$id]) || isset($seen[$id])) { throw new Failure('denied', 'INVALID_RERANK'); }
                    $seen[$id] = true;
                }
                $ranked = $reranked;
            }
            $entries = [];
            foreach ($ranked as $candidate) {
                [$release, $artifact] = $this->resolve($candidate, $releases);
                $entries[] = ['candidate' => $candidate, 'release' => $release, 'artifact' => $artifact];
            }
            $chosen = (new Selector())->choose($query, $entries);
            $selection = $this->selection($query, $chosen === [] ? 'empty' : 'ready', $chosen === [] ? 'NO_EVIDENCE' : 'RETRIEVED', $chosen);
            if ($chosen !== []) { $this->cache?->put($key, $selection); }
            return $selection;
        } catch (Failure $failure) {
            return $this->selection($query, $failure->status, $failure->reason, []);
        } catch (\Throwable) {
            return $this->selection($query, 'unavailable', 'RETRIEVAL_UNAVAILABLE', []);
        }
    }

    public function disclose(Query $query, Selection $selection): EvidenceSet
    {
        $now = $this->clock->now();
        try {
            if ($selection->binding !== $query->binding()) { throw new Failure('denied', 'QUERY_BINDING_MISMATCH'); }
            if ($selection->expiresAt <= $now) { throw new Failure('denied', 'EVIDENCE_EXPIRED'); }
            if ($selection->status !== 'ready') {
                if (!in_array($selection->status, ['empty', 'denied', 'unknown', 'unavailable', 'incompatible'], true)) {
                    throw new Failure('denied', 'INVALID_SELECTION');
                }
                return new EvidenceSet($query->scope->tenant, $query->runRef, $query->queryHash,
                    $selection->status, $selection->code, [], $now, $selection->expiresAt);
            }
            if ($selection->candidates === [] || !array_is_list($selection->candidates)
                || count($selection->candidates) > $query->maxFragments) { throw new Failure('denied', 'INVALID_SELECTION'); }
            $releases = $this->permitted($query, 'disclose');
            $this->spaces($query, $releases);
            $fragments = []; $bytes = 0; $seen = [];
            foreach ($selection->candidates as $candidate) {
                [$release, $artifact] = $this->resolve($candidate, $releases);
                $length = $candidate->end - $candidate->start; $bytes += $length;
                if ($length > $query->fragmentBytes || $bytes > $query->maxBytes || isset($seen[$candidate->key()])) {
                    throw new Failure('denied', 'EVIDENCE_LIMIT');
                }
                $seen[$candidate->key()] = true;
                $text = substr($artifact->text, $candidate->start - $artifact->start, $length);
                $m = $release->material;
                $fragments[] = ['text' => $text, 'citation' => ['corpus' => $release->corpus, 'release' => $release->id,
                    'release_hash' => $release->hash(), 'material' => $m->id, 'version' => $m->version,
                    'fragment' => $artifact->id, 'page' => $artifact->page, 'start' => $candidate->start, 'end' => $candidate->end,
                    'content_hash' => hash('sha256', $text)], 'owner_ref' => $m->ownerRef,
                    'access_policy_ref' => $m->accessPolicyRef, 'license_ref' => $m->licenseRef,
                    'bytes_ref' => $m->bytesRef, 'source_hash' => $m->sourceHash, 'page_hash' => $artifact->pageHash,
                    'artifact_hash' => $artifact->contentHash, 'box' => $artifact->box, 'locale' => $m->locale,
                    'programs' => $candidate->release->programs, 'validation_status' => 'validated', 'trust_class' => 'untrusted',
                    'space' => $release->space === null ? null : get_object_vars($release->space)];
            }
            // Last boundary after selection/cache/rerank/text materialization; historic results are never rewritten.
            foreach ($selection->candidates as $candidate) {
                try {
                    $fresh = $this->repository->get($query->scope, $candidate->release->corpus, $candidate->release->id);
                } catch (\DomainException) {
                    // The repository fails closed for both denied and unknown; retain the typed owner outcome.
                    $this->current($query, $candidate->release, 'read');
                    $this->current($query, $candidate->release, 'disclose');
                    throw new Failure('denied', 'SOURCE_CHANGED');
                }
                if ($fresh === null || $fresh->hash() !== $candidate->release->hash) { throw new Failure('denied', 'SOURCE_CHANGED'); }
                $this->current($query, $candidate->release, 'disclose');
            }
            $now = $this->clock->now();
            if ($selection->expiresAt <= $now) { throw new Failure('denied', 'EVIDENCE_EXPIRED'); }
            return new EvidenceSet($query->scope->tenant, $query->runRef, $query->queryHash, 'success', 'EVIDENCE_READY', $fragments, $now, $selection->expiresAt);
        } catch (Failure $failure) {
            return new EvidenceSet($query->scope->tenant, $query->runRef, $query->queryHash, $failure->status, $failure->reason, [], $now, $now);
        } catch (\DomainException) {
            return new EvidenceSet($query->scope->tenant, $query->runRef, $query->queryHash, 'denied', 'ACCESS_CHANGED', [], $now, $now);
        } catch (\Throwable) {
            return new EvidenceSet($query->scope->tenant, $query->runRef, $query->queryHash, 'unavailable', 'RETRIEVAL_UNAVAILABLE', [], $now, $now);
        }
    }

    /** @return array<string,Release> */
    private function permitted(Query $query, string $operation): array
    {
        $releases = []; $denied = false;
        foreach ($query->releases as $pin) {
            if ($pin->material->locale !== $query->locale || ($query->program !== null && !in_array($query->program, $pin->programs, true))) { continue; }
            try {
                $this->current($query, $pin, $operation);
                $release = $this->repository->get($query->scope, $pin->corpus, $pin->id);
                if ($release === null) { $denied = true; continue; }
                if ($release->hash() !== $pin->hash || Integrity::digest($release->material) !== Integrity::digest($pin->material)) {
                    throw new Failure('denied', 'PIN_MISMATCH');
                }
                $releases[$pin->key()] = $release;
            } catch (Failure $failure) {
                if ($failure->status !== 'denied') { throw $failure; }
                $denied = true;
            } catch (\DomainException) {
                // Retain unknown if permissions changed while the repository checked its own boundary.
                $this->current($query, $pin, 'read');
                $this->current($query, $pin, $operation); $denied = true;
            }
        }
        if ($releases === [] && $denied) { throw new Failure('denied', 'NO_AUTHORIZED_SOURCE'); }
        return $releases;
    }

    private function current(Query $query, ReleasePin $pin, string $operation): void
    {
        if (!$query->scope->matches($pin->material)) { throw new Failure('denied', 'SCOPE_DENIED'); }
        foreach ([fn(): Decision => $this->access->check($query->scope, $pin->material, $operation),
            fn(): Decision => $this->sources->check($query->scope, $pin->material)] as $check) {
            $decision = $check();
            if ($decision === Decision::Unknown) { throw new Failure('unknown', 'ACCESS_UNKNOWN'); }
            if ($decision !== Decision::Allowed) { throw new Failure('denied', 'SOURCE_DENIED'); }
        }
    }

    /** @param array<string,Release> $releases */
    private function spaces(Query $query, array $releases): void
    {
        if ($this->lexical->space() !== null) { throw new Failure('incompatible', 'SPACE_MISMATCH'); }
        if ($query->space !== null) {
            foreach ($releases as $release) {
                if ($release->space === null || $release->space->key() !== $query->space->key()) {
                    throw new Failure('incompatible', 'SPACE_MISMATCH');
                }
            }
        }
        if ($this->semantic === null) { return; }
        $space = $this->semantic->space();
        if ($query->space === null || $space === null || $space->key() !== $query->space->key()) { throw new Failure('incompatible', 'SPACE_MISMATCH'); }
        foreach ($releases as $release) {
            if ($release->space === null || $release->space->key() !== $space->key()) { throw new Failure('incompatible', 'SPACE_MISMATCH'); }
        }
    }

    /** @param array<string,Release> $releases
     * @return array{Release,Artifact}
     */
    private function resolve(Candidate $candidate, array $releases): array
    {
        $release = $releases[$candidate->release->key()] ?? null;
        if ($release === null) { throw new Failure('denied', 'INVALID_CANDIDATE'); }
        foreach ($release->extraction->artifacts as $artifact) {
            if ($artifact->id !== $candidate->artifact) { continue; }
            $offset = $candidate->start - $artifact->start;
            $body = substr($artifact->text, max(0, $offset), $candidate->end - $candidate->start);
            if ($artifact->kind !== 'fragment' || $offset < 0 || $candidate->end > $artifact->end || trim($body) === ''
                || preg_match('//u', substr($artifact->text, 0, max(0, $offset))) !== 1 || preg_match('//u', $body) !== 1
                || $artifact->sourceHash !== $release->material->sourceHash
                || ($release->pageHashes[$artifact->page - 1] ?? null) !== $artifact->pageHash
                || hash('sha256', $artifact->text) !== $artifact->contentHash) { throw new Failure('denied', 'INVALID_CANDIDATE'); }
            return [$release, $artifact];
        }
        throw new Failure('denied', 'INVALID_CANDIDATE');
    }

    /** @param list<Candidate> $candidates */
    private function selection(Query $query, string $status, string $code, array $candidates): Selection
    {
        return new Selection($query->binding(), $status, $code, $candidates, $this->clock->now() + $this->ttl);
    }
}
