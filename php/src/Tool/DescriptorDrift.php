<?php

declare(strict_types=1);

namespace AgenTools\Tool;

use AgenTools\Error\ToolError;
use AgenTools\Error\ToolErrorCode;
use AgenTools\Error\ToolExecutionException;

/** A descriptor changed after an action was prepared and before it was applied. */
final class DescriptorDrift extends ToolExecutionException
{
    public function __construct(DescriptorDigest $expected, DescriptorDigest $actual)
    {
        parent::__construct(new ToolError(
            ToolErrorCode::DescriptorDrift->value,
            'precondition',
            false,
            'Дескриптор инструмента изменился после подготовки действия',
            'Подготовленное предложение больше не описывает применяемый инструмент.',
            ['expected_digest' => $expected->value, 'actual_digest' => $actual->value],
        ));
    }
}
