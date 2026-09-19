<?php

declare(strict_types=1);

namespace AgenTools\Reference\Cli;

/** User-facing confirmation decision forwarded to the protocol layer (not gate policy). */
final readonly class ConfirmationChoice
{
    /** @param array<string, mixed> $arguments */
    public function __construct(
        public string $decision,
        public array $arguments,
    ) {
    }
}
