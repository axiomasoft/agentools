<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Budget;

use AgenToolsPlatform\Core\Common\UtcInstant;

/** Trusted host checks current entitlement, permissions and emergency deployment policy; pins grant nothing. */
interface BudgetGuard { public function assertCurrent(AdmissionRequest $request, UtcInstant $now): void; }
