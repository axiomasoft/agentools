<?php

declare(strict_types=1);

namespace AgenTools\Scenario;

/** SCN-28: the run outcome is not an action lifecycle state. */
enum ScenarioOutcome: string
{
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';
    case Compensated = 'COMPENSATED';
    case CompensationFailed = 'COMPENSATION_FAILED';
}
