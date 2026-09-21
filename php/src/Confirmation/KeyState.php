<?php

declare(strict_types=1);

namespace AgenTools\Confirmation;

enum KeyState: string
{
    case Active = 'active';
    case VerifyOnly = 'verify_only';
    case Compromised = 'compromised';
    case Retired = 'retired';
}
