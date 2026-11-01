<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Runtime;

/** Technical metadata only; result and proposal bytes are in host-owned encrypted payloads. */
final readonly class InvocationCheckpoint
{
    /** @param array<string, mixed> $data */
    public function __construct(public AdvanceLease $lease, public array $data) {}
    public function stage(): string
    {
        $stage = $this->data['stage'] ?? null;
        if (!is_string($stage)) { throw new \UnexpectedValueException('Missing checkpoint stage'); }
        return $stage;
    }
}
