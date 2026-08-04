<?php

declare(strict_types=1);

namespace AgenTools\Error;

class ToolExecutionException extends \RuntimeException
{
    public function __construct(public readonly ToolError $error)
    {
        parent::__construct($error->title);
    }
}
