<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Host;

use AgenToolsPlatform\Core\Actions\Effects\Request;

/** Load approved Actions binding/affirmative event; check TTL, hashes, epoch and cancellation.
 * A caller-created ConfirmedAction, boolean or cached permission is never sufficient.
 */
interface ReportEvidence
{
    public function assertCurrent(Envelope $envelope, Request $request): void;
}
