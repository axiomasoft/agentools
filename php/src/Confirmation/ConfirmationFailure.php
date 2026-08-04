<?php

declare(strict_types=1);

namespace AgenTools\Confirmation;

use AgenTools\Error\ToolError;
use AgenTools\Error\ToolErrorCode;
use AgenTools\Error\ToolExecutionException;

final class ConfirmationFailure extends ToolExecutionException
{
    public static function argsMismatch(): self
    {
        return self::from(ToolErrorCode::ConfirmationArgsMismatch, 'Аргументы не совпадают с подтверждённым предложением');
    }

    public static function expired(): self
    {
        return self::from(ToolErrorCode::ConfirmationExpired, 'Срок подтверждения истёк');
    }

    public static function spent(): self
    {
        return self::from(ToolErrorCode::ConfirmationTokenSpent, 'Токен подтверждения уже предъявлен');
    }

    public static function principalMismatch(): self
    {
        return self::from(ToolErrorCode::ConfirmationPrincipalMismatch, 'Токен предъявлен другим принципалом');
    }

    private static function from(ToolErrorCode $code, string $title): self
    {
        $parts = explode('.', $code->value);
        return new self(new ToolError($code->value, $parts[1], false, $title));
    }
}
