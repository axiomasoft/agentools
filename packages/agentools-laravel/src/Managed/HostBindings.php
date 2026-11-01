<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Managed;

use AgenTools\Confirmation\TokenKeyProvider;
use AgenTools\Contracts\Clock;
use AgenToolsPlatform\Core\Actions\{ActionPolicy, ActionRegistry, InputSchemaValidator};
use AgenToolsPlatform\Core\Actions\Confirmation\ConfirmationAuthority;
use AgenToolsPlatform\Core\Budget\BudgetPort;
use AgenToolsPlatform\Core\Common\{CanonicalHasher, CanonicalJson};
use AgenToolsPlatform\Core\Models\ModelTransport;
use AgenToolsPlatform\Core\Runtime\{InvocationGuard, InvocationPayloads};
use AgenToolsPlatform\Core\Security\HostAuthorization;
use AgenToolsPlatform\Postgres\Confirmation\Sealer;
use AgenToolsPlatform\Postgres\Payloads\EncryptedPayloadStore;
use AgenToolsPlatform\Postgres\Runtime\{Connection, ContextRevision};

/** Construct anew by a host scoped binding. No defaults, DB-selected classes or user settings. */
final readonly class HostBindings
{
    public const PORTS = [HostAuthorization::class => 'authorization', ContextRevision::class => 'revisions',
        ActionRegistry::class => 'registry', ActionPolicy::class => 'policy', InputSchemaValidator::class => 'schemas',
        ModelTransport::class => 'transport', BudgetPort::class => 'budget', InvocationGuard::class => 'guard',
        InvocationPayloads::class => 'payloads', EncryptedPayloadStore::class => 'encrypted', HostAdmission::class => 'host'];

    public function __construct(public Connection $connection, public \PDO $replayConnection, public string $schema,
        public CanonicalHasher $hasher, public CanonicalJson $json, public HostAuthorization $authorization,
        public ContextRevision $revisions, public ActionRegistry $registry, public ActionPolicy $policy,
        public InputSchemaValidator $schemas, public ConfirmationAuthority $confirmation, public TokenKeyProvider $keys,
        public Sealer $sealer, public Clock $clock, public ModelTransport $transport, public BudgetPort $budget,
        public InvocationGuard $guard, public InvocationPayloads $payloads, public EncryptedPayloadStore $encrypted,
        public HostAdmission $host) {}

    public function assertProduction(): void
    {
        // These are trusted server bindings, not a claim that arbitrary implementations are durable.
        // Concrete PostgreSQL journals/replay are constructed below; host stores still require qualification.
        foreach ([$this->authorization, $this->revisions, $this->policy, $this->schemas, $this->confirmation,
            $this->keys, $this->sealer, $this->clock, $this->transport, $this->budget, $this->guard,
            $this->payloads, $this->encrypted, $this->host] as $service) {
            self::assertProductionService($service);
        }
    }

    public static function assertProductionService(object $service): void
    {
        if (preg_match('/(?:^|\\\\)(?:Tests?|Testing|Reference)(?:\\\\|$)|Fake|InMemory|Scripted/i', $service::class) === 1) {
            throw new \LogicException('Production composition refuses testing or non-durable bindings');
        }
        if ($service instanceof ProductionBinding) { $service->assertProduction(); }
    }

    public function assertEnvironment(\Illuminate\Container\Container $app): void
    {
        if ($app->bound('agentools.testing') && $app->make('agentools.testing') === true) {
            if (!$app->bound('env') || $app->make('env') !== 'testing') {
                throw new \LogicException('Testing composition requires the explicit testing environment');
            }
        } else { $this->assertProduction(); }
    }

    public function reset(): void
    {
        $failed = false;
        // Every cleanup executes even when an earlier cleanup throws. Never retain a raw exception.
        foreach ([$this->connection->resetSession(...), $this->encrypted->reset(...), $this->host->reset(...)] as $reset) {
            try { $reset(); } catch (\Throwable) { $failed = true; }
        }
        try {
            if ($this->replayConnection->inTransaction()) { $this->replayConnection->rollBack(); }
            $this->replayConnection->exec('DISCARD ALL');
        } catch (\Throwable) { $failed = true; }
        if ($failed) { throw new LifecycleFailure(); }
    }
}
