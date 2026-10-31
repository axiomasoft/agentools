<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Tests;

use AgenTools\Schema\Canonicalizer;
use AgenToolsPlatform\Core\Common\CanonicalJson;

/** Test composition only: reuse the SDK encoder without a production core import. */
final readonly class JcsAdapter implements CanonicalJson
{
    public function __construct(private Canonicalizer $existing = new Canonicalizer())
    {
    }

    public function canonicalize(mixed $value): string
    {
        return $this->existing->canonicalize($value);
    }

    public function canonicalizeJson(string $json): string
    {
        return $this->existing->canonicalizeJson($json);
    }
}
