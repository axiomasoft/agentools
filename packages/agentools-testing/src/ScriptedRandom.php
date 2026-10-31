<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Testing;

/** Explicit entropy script. Test values must never be used as production credentials. */
final class ScriptedRandom
{
    /** @param list<array{purpose: string, value: string}> $script */
    public function __construct(private array $script) {}
    public function next(string $purpose): string
    {
        $entry = $this->script[0] ?? throw new \UnderflowException('Entropy script exhausted');
        if ($entry['purpose'] !== $purpose || $entry['value'] === '') {
            throw new \LogicException('Entropy script binding mismatch');
        }
        array_shift($this->script);
        return $entry['value'];
    }
    public function assertConsumed(): void
    {
        if ($this->script !== []) { throw new \LogicException('Unused entropy script'); }
    }
}
