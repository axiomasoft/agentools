<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Filament;

/** Explicit sensitive response. Never put this in a Livewire property, log or inspection projection. */
final readonly class DiagnosticResult
{
    public function __construct(#[\SensitiveParameter] public string $payload, public \DateTimeImmutable $expiresAt) {}
}
