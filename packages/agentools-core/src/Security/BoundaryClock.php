<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Security;

use AgenToolsPlatform\Core\Common\UtcInstant;

/** Qualified host time; no implicit wall clock or ManualClock qualification. */
interface BoundaryClock { public function now(): UtcInstant; }
