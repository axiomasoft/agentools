<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Tests\Runtime;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, InputPayload, InstanceId, PayloadHash, Ref, SessionId, SourceIdentity, TenantId};
use AgenToolsPlatform\Core\Context\ContextSnapshot;
use AgenToolsPlatform\Core\Security\{Correlation, ExecutionContext, TrustedInput};
use AgenToolsPlatform\Postgres\Runtime\{Connection, RepositoryContext, SessionBinding};
use PDO;

final readonly class Fixture
{
    public TestHost $host;
    public TestRevisions $revisions;
    public CanonicalHasher $hasher;
    public RepositoryContext $environment;

    public function __construct(PDO $pdo, string $schema, public TenantId $tenant)
    {
        $this->host = new TestHost($pdo, $tenant);
        $this->revisions = new TestRevisions($pdo);
        $this->hasher = new CanonicalHasher(new TestCanonicalJson());
        $this->environment = new RepositoryContext(new Connection($pdo, $schema), $this->hasher, $this->host, $this->revisions);
    }

    public function context(string $session = 'session'): ExecutionContext
    {
        return ExecutionContext::fromHost($this->host, new SessionId($this->tenant, $session), 'assist.read', new Correlation('trace', 'request'));
    }

    public function snapshot(ExecutionContext $context): ContextSnapshot
    {
        return new ContextSnapshot(new Ref($this->tenant, 'snapshot'), $this->revisions->revision,
            new PayloadHash('sha-256:' . str_repeat('c', 64)), $context->scopeHash($this->hasher));
    }

    public function binding(ExecutionContext $context, string $instance = 'instance'): SessionBinding
    {
        $hash = new PayloadHash('sha-256:' . str_repeat('a', 64));
        return new SessionBinding($context->session, new InstanceId($this->tenant, $instance), new Ref($this->tenant, 'code:v1'),
            $hash, $hash, new Ref($this->tenant, 'bundle:v1'), $hash, new Ref($this->tenant, 'chatom:conversation'));
    }

    public function input(ExecutionContext $context, string $content = 'a', string $event = 'event', string $instance = 'instance'): TrustedInput
    {
        return new TrustedInput($context, new SourceIdentity(new InstanceId($this->tenant, $instance), 'chat', $event),
            new InputPayload(new Ref($this->tenant, 'encrypted:input'), new PayloadHash('sha-256:' . str_repeat($content, 64)),
                new Ref($this->tenant, 'chatom:conversation'), new Ref($this->tenant, 'chatom:message')));
    }
}
