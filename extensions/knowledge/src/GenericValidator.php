<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge;

/** Source integrity only. Pedagogy/formulas/standards/answer keys remain Edufy rules. */
final readonly class GenericValidator implements Validator
{
    /** @param non-empty-list<string> $requiredKinds */
    public function __construct(public array $requiredKinds = ['node', 'fragment'])
    {
        Integrity::names($requiredKinds);
        if ($requiredKinds === [] || array_diff($requiredKinds, ['node', 'fragment', 'navigation', 'card']) !== []) {
            throw new \InvalidArgumentException('Known required kinds needed');
        }
    }

    public function validate(Material $material, SourceDocument $source, Extraction $extraction): ValidationResult
    {
        $errors = [];
        if ($material->bytesRef !== $source->bytesRef || hash('sha256', $source->bytes) !== $material->sourceHash) {
            $errors[] = 'source_mapping';
        }
        $byId = []; $perPage = [];
        foreach ($source->pages as $index => $text) {
            if (trim($text) === '' || preg_match('//u', $text) !== 1) { $errors[] = 'page_encoding_or_empty'; }
            $perPage[$index + 1] = [];
        }
        foreach ($extraction->artifacts as $artifact) {
            if (isset($byId[$artifact->id])) { $errors[] = 'duplicate_artifact'; }
            $byId[$artifact->id] = $artifact;
            $text = $source->pages[$artifact->page - 1] ?? null;
            if ($text === null || $artifact->start < 0 || $artifact->end <= $artifact->start || $artifact->end > strlen($text)) {
                $errors[] = 'coordinates'; continue;
            }
            $mapped = substr($text, $artifact->start, $artifact->end - $artifact->start);
            // Byte offsets in canonical UTF-8 page text, with both boundaries on codepoints.
            if (preg_match('//u', substr($text, 0, $artifact->start)) !== 1 || preg_match('//u', $mapped) !== 1) {
                $errors[] = 'encoding_boundary';
            }
            if ($artifact->sourceHash !== $material->sourceHash || $artifact->pageHash !== hash('sha256', $text)
                || $artifact->contentHash !== hash('sha256', $artifact->text) || trim($artifact->text) === '') {
                $errors[] = 'artifact_hash';
            }
            // Generated cards/navigation retain the supporting source range; extractive fragments/nodes match it.
            if (in_array($artifact->kind, ['node', 'fragment'], true) && $mapped !== $artifact->text) { $errors[] = 'source_text'; }
            if (preg_match('//u', $artifact->text) !== 1) { $errors[] = 'artifact_encoding'; }
            if ($artifact->box !== null) {
                [$x0, $y0, $x1, $y1] = $artifact->box;
                if (!is_finite($x0) || !is_finite($y0) || !is_finite($x1) || !is_finite($y1)
                    || $x0 < 0 || $y0 < 0 || $x1 > 1 || $y1 > 1 || $x0 >= $x1 || $y0 >= $y1) { $errors[] = 'box'; }
            }
            $perPage[$artifact->page][] = $artifact->kind;
        }
        foreach ($perPage as $kinds) {
            if (array_diff($this->requiredKinds, $kinds) !== []) { $errors[] = 'incomplete'; }
        }
        foreach ($byId as $artifact) {
            if ($artifact->parent === null) { continue; }
            $parent = $byId[$artifact->parent] ?? null;
            if ($parent === null || $parent->kind !== 'node' || $parent->page !== $artifact->page
                || $parent->start > $artifact->start || $parent->end < $artifact->end) { $errors[] = 'parent_mapping'; }
            $seen = [$artifact->id => true]; $cursor = $artifact;
            while ($cursor->parent !== null && isset($byId[$cursor->parent])) {
                if (isset($seen[$cursor->parent])) { $errors[] = 'hierarchy_cycle'; break; }
                $seen[$cursor->parent] = true; $cursor = $byId[$cursor->parent];
            }
        }
        return new ValidationResult($errors === [] ? 'validated' : 'failed', array_values(array_unique($errors)));
    }
}
