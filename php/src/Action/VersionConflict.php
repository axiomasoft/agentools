<?php

declare(strict_types=1);

namespace AgenTools\Action;

use AgenTools\Error\ErrorCategory;
use AgenTools\Error\ToolError;
use AgenTools\Error\ToolErrorCode;

final class VersionConflict extends LifecycleException
{
    public function __construct(int $expected, int $actual)
    {
        parent::__construct("Expected previous sequence {$expected}, got {$actual}.");
        $this->error = new ToolError(
            ToolErrorCode::VersionConflict->value,
            ErrorCategory::CONFLICT,
            true,
            'The action journal has advanced.',
            context: ['expected_prev_seq' => $expected, 'actual_prev_seq' => $actual],
        );
    }

    public readonly ToolError $error;
}
