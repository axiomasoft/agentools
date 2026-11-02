<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge;

interface Extractor
{
    /** PDF/OCR/subject-specific transformations belong to adapters, never the runtime core. */
    public function extract(Material $material, SourceDocument $source): Extraction;
}
