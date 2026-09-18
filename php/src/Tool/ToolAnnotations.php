<?php

declare(strict_types=1);

namespace AgenTools\Tool;

/** All four MCP annotation hints are explicit in an AgenTools descriptor. */
final readonly class ToolAnnotations
{
    public function __construct(
        public bool $readOnlyHint,
        public bool $destructiveHint,
        public bool $idempotentHint,
        public bool $openWorldHint,
    ) {
    }

    /** @return array<string, bool> */
    public function toArray(): array
    {
        return [
            'readOnlyHint' => $this->readOnlyHint,
            'destructiveHint' => $this->destructiveHint,
            'idempotentHint' => $this->idempotentHint,
            'openWorldHint' => $this->openWorldHint,
        ];
    }
}
