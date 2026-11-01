<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Chatom;

use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Context\TokenCounter;

/** Conservative UTF-8 byte bound for byte-tokenized text deployments, including rendered framing. */
final readonly class ByteTokenCounter implements TokenCounter
{
    public function __construct(private Ref $target, private string $additionalEnvelope = '') {}

    public function deployment(): Ref
    {
        return $this->target;
    }

    public function version(): string
    {
        return 'utf8-byte-bound/v1';
    }

    public function confidence(): string
    {
        return 'conservative';
    }

    public function count(string $rendered): int
    {
        return strlen($rendered) + strlen($this->additionalEnvelope) + 64;
    }
}
