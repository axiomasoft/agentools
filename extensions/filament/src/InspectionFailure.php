<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Filament;

/** Safe error without a retained raw previous exception. */
final class InspectionFailure extends \RuntimeException
{
    public function __construct(public readonly string $reason = 'read_failed') { parent::__construct($reason); }
}
