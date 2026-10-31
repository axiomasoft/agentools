<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Common;

/** RFC 8785 port. Adapters reuse existing JCS; core never owns a second encoder. */
interface CanonicalJson
{
    public function canonicalize(mixed $value): string;

    /** Must reject duplicate object names and preserve {} versus []. */
    public function canonicalizeJson(string $json): string;
}
