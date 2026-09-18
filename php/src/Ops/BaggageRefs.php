<?php

declare(strict_types=1);

namespace AgenTools\Ops;

use AgenTools\Error\ToolError;
use AgenTools\Error\ToolErrorCode;
use AgenTools\Error\ToolExecutionResult;

/** Opaque baggage identifiers only; substantive data is forbidden (AOB-29..AOB-31). */
final class BaggageRefs
{
    private const MAX_ITEMS = 64;
    private const MAX_BYTES = 8192;

    /** @var list<string> */
    private array $refs = [];

    public function add(string $opaqueId): self
    {
        if ($opaqueId === '' || !$this->isOpaque($opaqueId)) {
            throw new \InvalidArgumentException('Baggage may contain only opaque identifiers.');
        }

        $next = [...$this->refs, $opaqueId];
        $encoded = implode(',', $next);
        if (count($next) > self::MAX_ITEMS || strlen($encoded) > self::MAX_BYTES) {
            throw new BaggageLimitException(ToolExecutionResult::failure(new ToolError(
                ToolErrorCode::BaggageLimitExceeded->value,
                'budget',
                false,
                'Baggage limits were exceeded.',
            )));
        }

        $clone = clone $this;
        $clone->refs = $next;

        return $clone;
    }

    /** @return list<string> */
    public function refs(): array
    {
        return $this->refs;
    }

    private function isOpaque(string $value): bool
    {
        return preg_match('/^[a-zA-Z0-9._:-]+$/', $value) === 1;
    }
}

final class BaggageLimitException extends \RuntimeException
{
    public function __construct(public readonly ToolExecutionResult $result)
    {
        parent::__construct('Baggage limit exceeded.');
    }
}
