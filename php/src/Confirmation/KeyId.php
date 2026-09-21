<?php

declare(strict_types=1);

namespace AgenTools\Confirmation;

final readonly class KeyId
{
    public function __construct(public string $value)
    {
        if ($value === '') {
            throw new \InvalidArgumentException('Key id must not be empty.');
        }
    }
}
