<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Tests\Isolation;

use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Payloads\{PayloadDescriptor, PayloadPolicy};
use PDO;

final class TestPolicy implements PayloadPolicy
{
    public bool $denied = false;
    /** @var list<string> */
    public array $seen = [];

    public function __construct(private PDO $pdo)
    {
    }

    public function authorize(ExecutionContext $context, PayloadDescriptor $payload, string $operation): Ref
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('Policy IO under SQL lock');
        }
        $this->seen[] = $context->session->tenant->value . ':' . $operation;
        if ($this->denied) {
            throw new \RuntimeException('SECRET-policy-detail');
        }
        return new Ref($context->session->tenant, 'decision:payload');
    }
}
