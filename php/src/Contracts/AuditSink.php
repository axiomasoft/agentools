<?php

declare(strict_types=1);

namespace AgenTools\Contracts;

/** Optional export port; absence of an implementation must not break the package (AOB-25). */
interface AuditSink
{
    /** @param array<string, mixed> $auditEvent */
    public function export(array $auditEvent): void;
}
