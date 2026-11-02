<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge;

interface DocumentSource
{
    /** Resolve exact Files/Vaulter ref/version/hash after owner authorization. No implicit latest. */
    public function load(Material $material): SourceDocument;
}
