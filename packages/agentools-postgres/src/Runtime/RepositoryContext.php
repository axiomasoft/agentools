<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Runtime;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, ContractViolation, ErrorCode};
use AgenToolsPlatform\Core\Context\ContextSnapshot;
use AgenToolsPlatform\Core\Security\{ExecutionContext, HostAuthorization};

final readonly class RepositoryContext
{
    public function __construct(
        public Connection $db,
        public CanonicalHasher $hasher,
        private HostAuthorization $host,
        private ContextRevision $revisions,
    ) {
    }

    public function authorize(ExecutionContext $context, ?ContextSnapshot $snapshot = null): void
    {
        $this->db->assertIdle();
        $context->assertCurrent($this->host);
        if ($snapshot !== null) {
            $snapshot->assertScope($context, $this->hasher);
            if ($this->revisions->current($context, $snapshot->ref) !== $snapshot->revision) {
                throw new ContractViolation(ErrorCode::StaleContext);
            }
        }
    }
}
