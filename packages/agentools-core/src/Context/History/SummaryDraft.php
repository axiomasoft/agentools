<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context\History;

use AgenToolsPlatform\Core\Common\UtcInstant;
use AgenToolsPlatform\Core\Context\Classification;

/** Extractor output is transient, scoped data. The external content owner stores the view bytes. */
final readonly class SummaryDraft
{
    public function __construct(public SummaryFields $fields,public string $modelView,public string $extractorVersion,
        public Classification $classification,public UtcInstant $expiresAt)
    {
        if ($extractorVersion==='' || strlen($modelView)>65536) { throw new \InvalidArgumentException('Bounded versioned summary view required'); }
    }
}
