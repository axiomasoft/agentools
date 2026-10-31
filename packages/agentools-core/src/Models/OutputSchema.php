<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models;

final readonly class OutputSchema
{
    public function __construct(public string $name, public JsonObject $schema, public bool $strict = true)
    {
        if ($name === '') { throw new \InvalidArgumentException('Output schema needs a name'); }
    }
}
