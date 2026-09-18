<?php

declare(strict_types=1);

namespace AgenTools\Contracts;

interface TokenSigner
{
    public function sign(string $payload): string;

    public function verify(string $payload, string $signature): bool;
}
