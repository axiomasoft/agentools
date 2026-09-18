<?php

declare(strict_types=1);

namespace AgenTools\Effects;

/** ALC-25: an unresolved request must be reconciled, never blindly retried. */
final class EffectReconciliationRequired extends \LogicException
{
}
