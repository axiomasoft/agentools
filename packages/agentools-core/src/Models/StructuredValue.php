<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models;

/** Any JSON result, including an explicit JSON null distinct from absent output. */
final readonly class StructuredValue
{
    public function __construct(public mixed $value)
    {
        new \AgenToolsPlatform\Core\Models\JsonObject(['structured' => $value]);
    }
}
