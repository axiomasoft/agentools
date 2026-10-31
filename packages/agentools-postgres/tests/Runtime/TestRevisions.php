<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Tests\Runtime;

use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Runtime\ContextRevision;
use PDO;

final class TestRevisions implements ContextRevision
{
    public int $revision = 1;

    public function __construct(private PDO $pdo)
    {
    }

    public function current(ExecutionContext $context, Ref $snapshot): int
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('Snapshot IO under SQL transaction');
        }
        $context->session->tenant->assertSame($snapshot->tenant);
        return $this->revision;
    }
}
