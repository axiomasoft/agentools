<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Testing;

/** Explicit interleavings of bounded test steps; no threads, IO loop, locks or sleeps. */
final class RepeatableScheduler
{
    /** @var array<string, list<\Closure(): void>> */
    private array $tasks = [];
    /** @var list<string> */
    private array $trace = [];
    /** @param list<\Closure(): void> $steps */
    public function add(string $name, array $steps): void
    {
        if ($name === '' || isset($this->tasks[$name]) || $steps === []) { throw new \InvalidArgumentException('A unique bounded task is required'); }
        $this->tasks[$name] = $steps;
    }
    /** @param list<string> $order */
    public function run(array $order): void
    {
        if ($order === [] || $this->tasks === []) { throw new \InvalidArgumentException('Empty schedule cannot prove execution'); }
        $counts = array_count_values($order);
        foreach ($this->tasks as $name => $steps) {
            if (($counts[$name] ?? 0) !== count($steps)) { throw new \InvalidArgumentException('Schedule omits or repeats a required step'); }
        }
        if (array_diff(array_keys($counts), array_keys($this->tasks)) !== []) { throw new \InvalidArgumentException('Unknown scheduled task'); }
        foreach ($order as $name) {
            $step = array_shift($this->tasks[$name]);
            if ($step === null) { throw new \LogicException('Exhausted task'); }
            $this->trace[] = $name;
            $step();
        }
    }
    /** @return list<string> */
    public function trace(): array { return $this->trace; }
}
