<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Host;

use AgenToolsPlatform\Core\Actions\Effects\{LookupResult, Request};
use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Context\ToolResultView;

/** Edufy public owner service. Implementations must reauthorize inside their own boundary. */
interface EducationApi
{
    public function readReport(Envelope $envelope, Ref $report): ToolResultView;
    /** Atomic current ACL/consent/rights + expected report revision + native operation-key/hash. */
    public function amendReport(Envelope $envelope, Request $request): LookupResult;
    /** Current read ACL; missing receipt stays unknown. Never blindly resubmit after timeout. */
    public function lookupReport(Envelope $envelope, Request $request): LookupResult;
}
