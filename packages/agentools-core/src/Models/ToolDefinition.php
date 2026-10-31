<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models;

final readonly class ToolDefinition
{
    public function __construct(public string $name, public string $description, public JsonObject $inputSchema)
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,127}$/D', $name) !== 1
            || ($inputSchema->value['type'] ?? null) !== 'object') {
            throw new \InvalidArgumentException('Tools need a stable name and an object input schema');
        }
    }
}
