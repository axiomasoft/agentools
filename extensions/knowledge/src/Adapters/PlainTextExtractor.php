<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Adapters;

use AgenToolsPlatform\Knowledge\{Artifact, Extraction, Extractor, Material, SourceDocument};

/** Minimal extractive adapter for canonical text pages; no PDF/OCR dependencies. */
final class PlainTextExtractor implements Extractor
{
    public function extract(Material $material, SourceDocument $source): Extraction
    {
        $artifacts = [];
        foreach ($source->pages as $index => $text) {
            $page = $index + 1; $node = 'page:' . $page;
            foreach (['node', 'fragment'] as $kind) {
                $artifacts[] = new Artifact($kind === 'node' ? $node : 'fragment:' . $page, $kind, $page,
                    0, strlen($text), $text, $material->sourceHash, hash('sha256', $text), hash('sha256', $text),
                    $kind === 'node' ? null : $node);
            }
        }
        return new Extraction('plain-text/v1', $artifacts);
    }
}
