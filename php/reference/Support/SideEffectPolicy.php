<?php

declare(strict_types=1);

namespace AgenTools\Reference\Support;

/** INT-002 §3 — the only side-effect classification table for the reference slice. */
final class SideEffectPolicy
{
    /** @var list<string> */
    private const CORE = ['read', 'draft', 'write', 'irreversible'];

    public static function requiresPolicyHint(string $sideEffect): bool
    {
        return match ($sideEffect) {
            'write', 'irreversible' => true,
            'read', 'draft' => false,
            default => throw new \InvalidArgumentException('Unknown side_effect for reference slice classification.'),
        };
    }

    public static function requiresTwoPhase(string $sideEffect): bool
    {
        return match ($sideEffect) {
            'write', 'irreversible' => true,
            'read', 'draft' => false,
            default => throw new \InvalidArgumentException('Unknown side_effect for reference slice classification.'),
        };
    }

    public static function requiresDualControl(string $sideEffect): bool
    {
        return $sideEffect === 'irreversible';
    }

    /** @return list<string> */
    public static function coreValues(): array
    {
        return self::CORE;
    }
}
