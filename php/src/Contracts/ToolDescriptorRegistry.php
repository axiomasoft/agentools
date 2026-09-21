<?php

declare(strict_types=1);

namespace AgenTools\Contracts;

use AgenTools\Tool\ToolDescriptor;

interface ToolDescriptorRegistry
{
    public function descriptor(string $toolName): ?ToolDescriptor;
}
