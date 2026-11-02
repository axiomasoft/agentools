<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Retrieval;

use AgenToolsPlatform\Knowledge\{Artifact, Release};

/** Bounded extractive heuristic, not a claim of pedagogical or semantic sufficiency. */
final class Selector
{
    /** @return list<string> */
    public static function terms(string $text): array
    {
        return array_values(array_unique(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $text) ?: [],
            static fn(string $term): bool => $term !== '')));
    }

    public static function matches(string $text, string $term): bool
    {
        return preg_match('/' . preg_quote($term, '/') . '/iu', $text) === 1;
    }

    /** @param list<array{candidate:Candidate,release:Release,artifact:Artifact}> $ranked
     * @return list<Candidate>
     */
    public function choose(Query $query, array $ranked): array
    {
        $pool = [];
        foreach ($ranked as $entry) {
            $pool[] = ['candidate' => $this->minimal($query, $entry['candidate'], $entry['artifact']),
                'release' => $entry['release']];
        }
        $selected = []; $spans = []; $seenSources = []; $hashes = []; $used = 0;
        // First give distinct materials a place, then fill remaining capacity in rank order.
        foreach ([true, false] as $diversity) {
            foreach ($pool as $entry) {
                $candidate = $entry['candidate']; $release = $entry['release'];
                $source = $release->material->id;
                if ($diversity && isset($seenSources[$source])) { continue; }
                $artifact = null;
                foreach ($release->extraction->artifacts as $a) { if ($a->id === $candidate->artifact) { $artifact = $a; break; } }
                if ($artifact === null) { throw new Failure('denied', 'INVALID_CANDIDATE'); }
                $duplicate = false;
                foreach ($spans as $prior) {
                    if ($prior['material'] === $release->material->key() && $prior['page'] === $artifact->page
                        && $prior['start'] < $candidate->end && $candidate->start < $prior['end']) { $duplicate = true; }
                }
                $body = substr($artifact->text, $candidate->start - $artifact->start, $candidate->end - $candidate->start);
                $hash = hash('sha256', trim($body));
                if ($duplicate || isset($hashes[$hash]) || $used + strlen($body) > $query->maxBytes) { continue; }
                $selected[] = $candidate; $seenSources[$source] = true; $hashes[$hash] = true; $used += strlen($body);
                $spans[] = ['material' => $release->material->key(), 'page' => $artifact->page,
                    'start' => $candidate->start, 'end' => $candidate->end];
                if (count($selected) >= $query->maxFragments) { return $selected; }
            }
        }
        return $selected;
    }

    private function minimal(Query $query, Candidate $candidate, Artifact $artifact): Candidate
    {
        $body = substr($artifact->text, $candidate->start - $artifact->start, $candidate->end - $candidate->start);
        preg_match_all('/[^.!?。\n]+[.!?。]?/u', $body, $sentences, PREG_OFFSET_CAPTURE);
        $best = $body; $offset = 0; $bestScore = -1;
        foreach ($sentences[0] as [$sentence, $at]) {
            $score = 0;
            foreach (self::terms($query->normalized) as $term) { if (self::matches($sentence, $term)) { ++$score; } }
            if ($score > $bestScore || ($score === $bestScore && strlen($sentence) < strlen($best))) {
                $best = $sentence; $offset = $at; $bestScore = $score;
            }
        }
        $start = 0;
        if (strlen($best) > $query->fragmentBytes) {
            foreach (self::terms($query->normalized) as $term) {
                if (preg_match('/' . preg_quote($term, '/') . '/iu', $best, $match, PREG_OFFSET_CAPTURE) === 1) {
                    $start = max(0, $match[0][1] - intdiv($query->fragmentBytes, 3)); break;
                }
            }
        }
        while ($start > 0 && preg_match('//u', substr($best, 0, $start)) !== 1) { --$start; }
        $length = min($query->fragmentBytes, strlen($best) - $start);
        while ($length > 0 && preg_match('//u', substr($best, $start, $length)) !== 1) { --$length; }
        $piece = substr($best, $start, $length);
        $leading = strlen($piece) - strlen(ltrim($piece)); $piece = trim($piece);
        if ($piece === '') { throw new Failure('denied', 'EMPTY_FRAGMENT'); }
        $begin = $candidate->start + $offset + $start + $leading;
        return new Candidate($candidate->release, $candidate->artifact, $begin, $begin + strlen($piece), $candidate->score);
    }
}
