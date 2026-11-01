<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\ReadJourney;

use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Context\TokenCounter;

/** Conservative UTF-8 byte bound ONLY for the declared scripted deployment; no provider claim. */
final readonly class Counter implements TokenCounter
{
    public function __construct(private Ref $target) {}
    public function deployment(): Ref { return $this->target; }
    public function version(): string { return 'scripted-support-byte-bound/v1'; }
    public function confidence(): string { return 'conservative'; }
    public function count(string $rendered): int { return strlen($rendered); }
}
