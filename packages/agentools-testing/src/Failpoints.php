<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Testing;

/** Finite one-shot injections, with explicit required-hit checks. */
final class Failpoints
{
    /** @var array<string, int> */
    private array $armed = [];
    public function arm(FaultPoint $point, int $occurrence = 1): void
    {
        if ($occurrence < 1 || isset($this->armed[$point->value])) { throw new \InvalidArgumentException('Invalid/repeated failpoint arm'); }
        $this->armed[$point->value] = $occurrence;
    }
    public function hit(FaultPoint $point): void
    {
        if (!isset($this->armed[$point->value])) { return; }
        if (--$this->armed[$point->value] === 0) {
            unset($this->armed[$point->value]);
            throw new InjectedFault($point);
        }
    }
    public function assertConsumed(): void
    {
        if ($this->armed !== []) { throw new \LogicException('Required failpoint was not reached'); }
    }
}
