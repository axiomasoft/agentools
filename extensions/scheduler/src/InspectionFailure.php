<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Scheduler;

/** Bounded adapter failure; no SQL, payload or previous exception. */
final class InspectionFailure extends \RuntimeException {}
