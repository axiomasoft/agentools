<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Testing;

final class InjectedFault extends \RuntimeException
{
    public function __construct(public readonly FaultPoint $point)
    {
        parent::__construct('Scripted fault: ' . $point->value);
    }
}
