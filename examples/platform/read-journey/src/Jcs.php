<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\ReadJourney;

use AgenTools\Schema\Canonicalizer;
use AgenToolsPlatform\Core\Common\CanonicalJson;

final readonly class Jcs implements CanonicalJson
{
    public function canonicalize(mixed $value): string { return (new Canonicalizer())->canonicalize($value); }
    public function canonicalizeJson(string $json): string { return (new Canonicalizer())->canonicalizeJson($json); }
}
