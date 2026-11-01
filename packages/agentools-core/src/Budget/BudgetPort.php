<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Budget;

use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Common\UtcInstant;

/** Authoritative host owner. Unknown never authorizes release, recharge or provider IO. */
interface BudgetPort
{
    public function admit(AdmissionRequest $request): Admission;
    public function status(AdmissionRequest $request): Admission;
    public function settle(Ref $reservation, Ref $usageEvent): void;
    public function release(Ref $reservation, string $provenNotSentReason): void;
    public function renew(Ref $reservation, UtcInstant $deadline): Admission;
}
