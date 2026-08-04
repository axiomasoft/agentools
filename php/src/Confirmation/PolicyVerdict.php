<?php

declare(strict_types=1);

namespace AgenTools\Confirmation;

enum PolicyVerdict: string
{
    case Deny = 'deny';
    case Ask = 'ask';
    case Allow = 'allow';
}
