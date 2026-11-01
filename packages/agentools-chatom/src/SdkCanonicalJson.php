<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Chatom;

use AgenToolsPlatform\Core\Common\CanonicalJson;
use AgenTools\Schema\Canonicalizer;

/** Reuses the existing SDK RFC 8785 implementation. */
final readonly class SdkCanonicalJson implements CanonicalJson
{
    public function canonicalize(mixed $value): string { return (new Canonicalizer())->canonicalize($value); }
    public function canonicalizeJson(string $json): string { return (new Canonicalizer())->canonicalizeJson($json); }
}
