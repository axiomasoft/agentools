<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Tests\Profiles;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, ContractViolation, ErrorCode, InstanceId, Ref, SessionId};
use AgenToolsPlatform\Core\Profiles\{BundleManifest, BundleValidator, SessionPin, UseBoundary};
use AgenToolsPlatform\Core\Security\{Correlation, ExecutionContext};
use AgenToolsPlatform\Postgres\Profiles\{Authority, BundleRepository, SessionRepository};
use AgenToolsPlatform\Postgres\Runtime\{Connection, RepositoryContext};
use AgenToolsPlatform\Postgres\Tests\Runtime\TestRevisions;
use AgenToolsPlatform\Tests\Profiles\Fixture as CoreFixture;
use AgenToolsPlatform\Tests\TestHost;
use PDO;

final class TestAuthority implements Authority
{
    public bool $denied = false;
    public bool $emergency = false;
    public bool $revoked = false;
    public bool $badProvenance = false;
    public bool $provenanceConflict = false;
    public bool $incompatible = false;
    /** @var list<string> */
    public array $boundaries = [];
    public function __construct(private PDO $pdo) {}
    private function idle(): void { if ($this->pdo->inTransaction()) { throw new \LogicException('Owner IO under Profiles transaction'); } }
    public function authorize(ExecutionContext $context, string $operation): void
    {
        $this->idle(); if ($this->denied) { throw new ContractViolation(ErrorCode::AccessDenied); }
    }
    public function provenance(ExecutionContext $context, BundleManifest $manifest): void
    {
        $this->idle(); if ($this->badProvenance) { throw new ContractViolation(ErrorCode::PolicyConflict); }
        if ($this->provenanceConflict) { throw new \AgenToolsPlatform\Core\Common\IntegrityConflict('Evaluation hash mismatch'); }
    }
    public function use(ExecutionContext $context, BundleManifest $manifest, UseBoundary $boundary): void
    {
        $this->idle(); $this->boundaries[] = $boundary->value;
        if ($this->emergency || $this->revoked) { throw new ContractViolation(ErrorCode::AccessDenied); }
    }
    public function compatibility(ExecutionContext $context, SessionPin $from, BundleManifest $target): string
    {
        $this->idle(); if ($this->incompatible) { throw new ContractViolation(ErrorCode::PolicyConflict); }
        return 'validation:session-compatibility';
    }
}

final class Fixture
{
    public CoreFixture $core;
    public TestHost $host;
    public TestAuthority $authority;
    public RepositoryContext $environment;
    public BundleRepository $bundles;
    public SessionRepository $sessions;
    public function __construct(PDO $pdo, string $schema, string $tenant = 'tenant-a')
    {
        $this->core = new CoreFixture($tenant); $this->host = new TestHost($this->core->tenant);
        $this->authority = new TestAuthority($pdo);
        $this->environment = new RepositoryContext(new Connection($pdo, $schema), new CanonicalHasher($this->core->json), $this->host, new TestRevisions($pdo));
        $this->bundles = new BundleRepository($this->environment, new BundleValidator($this->core->catalog, $this->core->compiler), $this->authority, $this->core->json);
        $this->sessions = new SessionRepository($this->environment, $this->bundles, $this->authority);
    }
    public function context(string $session = 'session'): ExecutionContext
    {
        return ExecutionContext::fromHost($this->host, new SessionId($this->core->tenant, $session), 'profiles.manage', new Correlation('trace', 'request'));
    }
    public function ref(string $id): Ref { return new Ref($this->core->tenant, $id); }
    public function instance(string $id = 'assistant'): InstanceId { return new InstanceId($this->core->tenant, $id); }
    public function publish(int $version = 7): BundleManifest
    {
        $m = $this->core->manifest($version); $c = $this->context();
        $this->bundles->draft($c, $m); $this->bundles->validate($c, $m->bundle, 1); $this->bundles->publish($c, $m->bundle, 2);
        return $m;
    }
    /** @phpstan-impure Checks current authority and mutates durable assignment state. */
    public function assign(int $version = 7, int $expected = 0, string $command = 'assignment'): int
    {
        return $this->sessions->assign($this->context(), $this->ref($command), $this->instance(), $this->core->definition, $this->core->manifest($version)->bundle, $expected);
    }
}
