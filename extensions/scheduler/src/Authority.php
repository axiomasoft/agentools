<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Scheduler;

use AgenToolsPlatform\Core\Common\{Ref, UtcInstant};
use AgenToolsPlatform\Core\Security\ExecutionContext;

/** Trusted current host principal/template owner. Creation-time consent is never a dispatch grant. */
interface Authority
{
    public function context(): ExecutionContext;
    public function now(): UtcInstant;
    public function authorize(ExecutionContext $context, Schedule $schedule, string $operation): void;
    public function prepare(ExecutionContext $context, Schedule $schedule, UtcInstant $plannedAt, Ref $inputRef): Prepared;
    public function reset(): void;
}
