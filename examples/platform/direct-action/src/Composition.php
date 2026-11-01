<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\DirectAction;

use AgenToolsPlatform\Core\Actions\{ActionApplication, ActionDefinition, ActionHandler, ActionRegistration, ActionRegistry, ActionRequest, ActionSubmission, EffectClass};
use AgenToolsPlatform\Core\Common\{CanonicalHasher, ProposalId, Ref, SessionId, UtcInstant};
use AgenToolsPlatform\Core\Security\{Correlation, ExecutionContext};
use AgenToolsPlatform\Postgres\Actions\SubmissionLifecycle;
use AgenToolsPlatform\Postgres\Runtime\{Connection, ContextRevision, RepositoryContext};
use PDO;

/** No planner, transport, invocation, or owner IO is constructed on the direct path. */
final readonly class Composition
{
    public CanonicalHasher $hasher;
    public ActionDefinition $definition;
    public ActionRegistry $registry;
    public Policy $policy;
    public ActionApplication $application;
    public RepositoryContext $environment;

    public function __construct(PDO $pdo, string $schema, public Host $host)
    {
        $json = new Jcs();
        $this->hasher = new CanonicalHasher($json);
        $this->definition = new ActionDefinition('record.read', '1', 'records.owner', InputSchema::JSON, '{"type":"object"}',
            EffectClass::Read, ['records.read'], false, 1000, 1, 0, 3000, 'native_key', null,
            'tenant-region', 'technical-ref-only', 'omit-payload', ['definition', 'actor', 'outcome'], null, $json);
        $handler = new class implements ActionHandler {
            public function handlerId(): string { return 'records.owner'; }
        };
        $this->registry = new ActionRegistry([new ActionRegistration($this->definition, $handler)]);
        $this->policy = new Policy($this->definition);
        $revisions = new class implements ContextRevision {
            public function current(ExecutionContext $context, Ref $snapshot): int {
                throw new \LogicException('Direct intake has no assistant context snapshot');
            }
        };
        $this->environment = new RepositoryContext(new Connection($pdo, $schema), $this->hasher, $host, $revisions);
        $schemas = new InputSchema();
        $this->application = new ActionApplication($this->registry, $this->policy, $host, $schemas,
            new SubmissionLifecycle($this->environment, $this->registry, $this->policy, $schemas));
    }

    public function context(string $session = 'direct-session'): ExecutionContext
    {
        return ExecutionContext::fromHost($this->host, new SessionId($this->host->tenant, $session),
            'direct.read', new Correlation('direct-trace', 'direct-request'));
    }

    public function submission(string $key = 'submission-1', string $input = '{"query":"hello"}',
        ?ProposalId $proposal = null, ?ExecutionContext $context = null, string $deadline = '2099-01-01T00:00:00Z'): ActionSubmission
    {
        $tenant = $this->host->tenant;
        return new ActionSubmission(new ActionRequest($this->definition->ref, new Ref($tenant, 'host:origin'), $proposal,
            new Ref($tenant, $key), new Ref($tenant, 'host:input:' . $key),
            $this->hasher->hash($tenant, 'action-input', json_decode($input, false, 512, JSON_THROW_ON_ERROR)),
            $context ?? $this->context(), new UtcInstant($deadline), $this->hasher), $input, $this->hasher, new Jcs());
    }
}
