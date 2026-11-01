<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context;

/** Explainable refusal, compatible with the original DomainException error code. */
final class BudgetOverflow extends \DomainException
{
    /** @param array<string, mixed> $report */
    public function __construct(public readonly array $report) { parent::__construct('CONTEXT_MANDATORY_OVERFLOW'); }
}
