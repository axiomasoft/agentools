<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\ConfirmedWrite;

use AgenToolsPlatform\Core\Actions\{ActionAccess, ActionApplication, ActionDefinition, ActionHandler, ActionPolicy, ActionRegistration, ActionRegistry, ActionRequest, ActionSubmission, EffectClass, InputSchemaValidator};
use AgenToolsPlatform\Core\Common\{CanonicalHasher, ContractViolation, ErrorCode, Ref, SessionId, UtcInstant};
use AgenToolsPlatform\Core\Security\{Correlation, ExecutionContext};
use AgenToolsPlatform\Examples\DirectAction\Jcs;
use AgenToolsPlatform\Postgres\Actions\SubmissionLifecycle;
use AgenToolsPlatform\Postgres\Confirmation\{ScopedReplayStore, Service};
use AgenToolsPlatform\Postgres\Runtime\{Connection, ContextRevision, RepositoryContext};
use PDO;

final readonly class Composition
{
    public const INPUT='{"type":"object","required":["recipient","document","amount"],"properties":{"recipient":{"type":"string"},"document":{"type":"string"},"amount":{"type":"integer"}},"additionalProperties":false}';
    public CanonicalHasher $hasher;
    public ActionDefinition $definition;
    public ActionRegistry $registry;
    public ActionPolicy $policy;
    public RepositoryContext $environment;
    public ActionApplication $actions;
    public Service $confirmation;

    public function __construct(PDO $pdo,PDO $replayPdo,string $schema,public Host $host,KeyMaterial $keys,?ActionDefinition $definition=null)
    {
        $json=new Jcs();
        $this->hasher=new CanonicalHasher($json);
        $this->definition=$definition??new ActionDefinition('document.write','1','documents.owner',self::INPUT,'{"type":"object"}',EffectClass::Write,
            ['documents.write'],true,1000,1,0,3000,'native_key',null,'tenant-region','technical-ref-only','omit-payload',['actor','outcome'],null,$json);
        $handler=new class implements ActionHandler { public function handlerId(): string { return 'documents.owner'; } };
        $this->registry=new ActionRegistry([new ActionRegistration($this->definition,$handler)]);
        $this->policy=new class($this->definition) implements ActionPolicy {
            public function __construct(private ActionDefinition $definition) {}
            public function current(ExecutionContext $context): ActionAccess {
                $keys=[ActionAccess::key($this->definition->ref)];
                return new ActionAccess($keys,$keys,$keys,$keys,$keys,['documents.write']);
            }
        };
        $revisions=new class implements ContextRevision {
            public function current(ExecutionContext $context,Ref $snapshot): int { throw new \LogicException('No Assistant snapshot on direct write path'); }
        };
        $this->environment=new RepositoryContext(new Connection($pdo,$schema),$this->hasher,$host,$revisions);
        $validator=new class implements InputSchemaValidator {
            public function assertValid(string $schemaJson,string $inputJson): void {
                $value=json_decode($inputJson,true,512,JSON_THROW_ON_ERROR);
                if ($schemaJson!==(new Jcs())->canonicalizeJson(Composition::INPUT) || !is_array($value)
                    || count($value)!==3 || !is_string($value['recipient']??null) || !is_string($value['document']??null) || !is_int($value['amount']??null)) {
                    throw new ContractViolation(ErrorCode::InvalidInput);
                }
            }
        };
        $this->actions=new ActionApplication($this->registry,$this->policy,$host,$validator,
            new SubmissionLifecycle($this->environment,$this->registry,$this->policy,$validator));
        $this->confirmation=new Service($this->environment,$this->actions,$this->registry,$this->policy,$host,$keys,
            new ScopedReplayStore($replayPdo,$schema),$keys,$host);
    }
    public function context(string $session='confirmed-session'): ExecutionContext
    {
        return ExecutionContext::fromHost($this->host,new SessionId($this->host->tenant,$session),'document.write',new Correlation('confirmed-trace','confirmed-request'));
    }
    public function submission(string $input='{"recipient":"r1","document":"d1","amount":100}',string $key='write-1',?ExecutionContext $context=null): ActionSubmission
    {
        $tenant=$this->host->tenant;
        return new ActionSubmission(new ActionRequest($this->definition->ref,new Ref($tenant,'host:document-origin'),null,new Ref($tenant,$key),
            new Ref($tenant,'host:document-input:'.$key),$this->hasher->hash($tenant,'action-input',json_decode($input,false,512,JSON_THROW_ON_ERROR)),
            $context??$this->context(),new UtcInstant('2099-01-01T00:00:00Z'),$this->hasher),$input,$this->hasher,new Jcs());
    }
}
