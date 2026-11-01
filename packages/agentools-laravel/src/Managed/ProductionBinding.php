<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Managed;

/** Trusted decorators expose their nested bindings to the same production guard. */
interface ProductionBinding
{
    public function assertProduction(): void;
}
