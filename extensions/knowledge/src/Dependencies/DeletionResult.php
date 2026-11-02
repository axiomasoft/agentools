<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Dependencies;

final readonly class DeletionResult
{
    public function __construct(public string $status, public ?string $receiptRef = null)
    {
        if (!in_array($status,['succeeded','failed','unknown','unsupported','pending'],true)
            || ($status === 'succeeded' && ($receiptRef === null || trim($receiptRef) === ''))
            || ($receiptRef !== null && trim($receiptRef) === '')) {
            throw new \InvalidArgumentException('Deletion observation required; success needs provider evidence');
        }
    }
}
