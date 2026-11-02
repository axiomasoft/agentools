<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge;

/** Transient adapter result. Raw source bytes and page bodies are never stored in a release. */
final readonly class SourceDocument
{
    /** @param non-empty-list<string> $pages */
    public function __construct(public string $bytesRef, public string $bytes, public array $pages)
    {
        Integrity::text($bytesRef);
        if ($pages === [] || !array_is_list($pages)) { throw new \InvalidArgumentException('Ordered pages required'); }
    }
}
