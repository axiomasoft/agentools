<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Common;

final class ContractViolation extends \DomainException
{
    public function __construct(public readonly ErrorCode $errorCode)
    {
        parent::__construct($errorCode->value);
    }
}
