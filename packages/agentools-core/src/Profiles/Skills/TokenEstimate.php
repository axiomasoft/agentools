<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Skills;

final readonly class TokenEstimate
{
    private function __construct(public ?int $upperBound, public string $confidence) {}
    public static function unknown(): self { return new \AgenToolsPlatform\Core\Profiles\Skills\TokenEstimate(null,'unknown'); }
    public static function bounded(int $upperBound, bool $exact = false): self
    {
        if ($upperBound < 1 || $upperBound > 9007199254740991) { throw new \InvalidArgumentException('Positive known token bound required'); }
        return new \AgenToolsPlatform\Core\Profiles\Skills\TokenEstimate($upperBound,$exact?'exact':'conservative_bound');
    }
}
