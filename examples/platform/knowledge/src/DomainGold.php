<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\Knowledge;

use AgenToolsPlatform\Knowledge\{Extraction,Material,SourceDocument,ValidationResult};

/** External host/expert port. No generic formula, age, standards or pedagogy truth lives in core. */
interface DomainGold
{
    public function check(Material $material, SourceDocument $source, Extraction $extraction): ValidationResult;
    public function evidenceRef(): string;
}
