<?php

declare(strict_types=1);

namespace AgenTools\Tool;

use AgenTools\Schema\Canonicalizer;

/** SHA-256 digest of the five-member descriptor input defined by TLP-18. */
final readonly class DescriptorDigest
{
    private function __construct(public string $value)
    {
    }

    public static function for(ToolDescriptor $descriptor, Canonicalizer $canonicalizer): self
    {
        return new self('sha-256:' . hash('sha256', $canonicalizer->canonicalize($descriptor->digestInput())));
    }
}
