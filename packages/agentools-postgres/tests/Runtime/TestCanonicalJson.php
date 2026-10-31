<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Tests\Runtime;

use AgenTools\Schema\Canonicalizer;
use AgenToolsPlatform\Core\Common\CanonicalJson;

final readonly class TestCanonicalJson implements CanonicalJson
{
    public function canonicalize(mixed $value): string
    {
        return (new Canonicalizer())->canonicalize($value);
    }

    public function canonicalizeJson(string $json): string
    {
        return (new Canonicalizer())->canonicalizeJson($json);
    }
}
