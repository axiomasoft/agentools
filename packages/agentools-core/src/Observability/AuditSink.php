<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Core\Observability;

use AgenToolsPlatform\Core\Common\Ref;

interface AuditSink
{
    /** Durable, immutable tenant/event identity + hash. Exceptions must stop the protected operation. */
    public function append(Event $event): Ref;
    public function reset(): void;
}
