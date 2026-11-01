<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Filament\Profiles;

use AgenToolsPlatform\Core\Profiles\Composition\ProfileConflict;

/** Buffered, allowlisted projection. Never contains compiled prompts or exception messages. */
final readonly class Result
{
    /**
     * @param array<string, mixed> $fields
     * @param list<string> $issues
     */
    public function __construct(public string $code, public array $fields = [], public array $issues = [],
        public ?ProfileConflict $conflict = null) {}
}
