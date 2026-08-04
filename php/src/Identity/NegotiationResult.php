<?php

declare(strict_types=1);

namespace AgenTools\Identity;

use AgenTools\Error\ToolError;

final readonly class NegotiationResult
{
    public function __construct(public NegotiationMode $mode, public ?ToolError $error = null)
    {
        if (($mode === NegotiationMode::Rejected) !== ($error !== null)) {
            throw new \InvalidArgumentException('Only a rejected negotiation carries a tool error.');
        }
    }
}
