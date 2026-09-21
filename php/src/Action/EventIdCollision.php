<?php

declare(strict_types=1);

namespace AgenTools\Action;

use AgenTools\Error\ErrorCategory;
use AgenTools\Error\ToolError;
use AgenTools\Error\ToolErrorCode;

final class EventIdCollision extends LifecycleException
{
    public function __construct(string $eventId)
    {
        parent::__construct('The event_id collides with a different content hash.');
        $this->error = new ToolError(
            ToolErrorCode::EventIdCollision->value,
            ErrorCategory::CONFLICT,
            false,
            'The event_id collides with a different content hash.',
            context: ['event_id' => $eventId],
        );
    }

    public readonly ToolError $error;
}
