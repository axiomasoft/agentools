<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge;

interface Validator
{
    public function validate(Material $material, SourceDocument $source, Extraction $extraction): ValidationResult;
}
