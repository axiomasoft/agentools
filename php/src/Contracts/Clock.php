<?php

declare(strict_types=1);

namespace AgenTools\Contracts;

/** Injectable time source for recording non-deterministic input without reading system clocks in fold logic. */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
