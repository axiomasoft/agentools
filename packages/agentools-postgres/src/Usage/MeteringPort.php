<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Postgres\Usage;

use AgenToolsPlatform\Core\Usage\UsageEvent;

/** Host maps its actual metering API. Idempotency is tenant/event identity + canonical hash.
 * Corrections replace the superseded fact; delta APIs must explicitly map replacement semantics.
 */
interface MeteringPort
{
    public function status(UsageEvent $event): ExportStatus;
    public function export(UsageEvent $event): ExportStatus;
}
