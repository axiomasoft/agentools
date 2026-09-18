<?php

declare(strict_types=1);

namespace AgenTools\Scenario;

/** @phpstan-type StepResults array<string, array<string, mixed>> */
final readonly class ScenarioResult
{
    /** @param array<string, array<string, mixed>> $stepResults */
    public function __construct(
        public ScenarioOutcome $outcome,
        public array $stepResults,
        public ?string $stoppedAtStepId = null,
    ) {
    }
}
