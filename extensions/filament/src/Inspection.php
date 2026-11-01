<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Filament;

/** No checkpoint, payload, exception message or dereferenceable diagnostic handle. */
final readonly class Inspection
{
    /** @param array<string, string|int|null> $fields */
    public function __construct(public string $code, public array $fields = []) {}
}
