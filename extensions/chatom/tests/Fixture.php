<?php

declare(strict_types=1);

namespace AgenToolsPlatform\ChatomBridge\Tests;

require_once __DIR__.'/bootstrap.php';

use AgenToolsPlatform\ChatomBridge\{Incoming, Invalidation, PostgresDelivery};
use AgenToolsPlatform\Core\Common\{CanonicalHasher, ContractViolation, ErrorCode, IdentityKeys, InputPayload, InstanceId, Ref, SessionId, SourceIdentity, TenantId, UtcInstant};
use AgenToolsPlatform\Core\Context\ContextSnapshot;
use AgenToolsPlatform\Core\Context\History\{MessageRef, SummaryVersion, WorkingState};
use AgenToolsPlatform\Core\Security\{Correlation, ExecutionContext, HostAuthorization, HostIdentity, TrustedInput};
use AgenToolsPlatform\Examples\Chatom\ScriptedDestination;
use AgenToolsPlatform\Postgres\Delivery\{DeliveryRepository, DeliverySubmission};
use AgenToolsPlatform\Postgres\Payloads\{FileEncryptedPayloadStore, InvocationPayloadStore, PayloadDescriptor, PayloadPolicy, PayloadRepository};
use AgenToolsPlatform\Postgres\Runtime\{Connection, ContextRevision, RepositoryContext, SessionBinding, SessionRepository};
use AgenToolsPlatform\Postgres\Summaries\{Authority, Repository};
use AgenToolsPlatform\Postgres\{Migrator, Tests\IsolatedDatabase};
use AgenToolsPlatform\Tests\JcsAdapter;

final class Host implements HostAuthorization
{
    public bool $allowed=true;
    public bool $sourceAllowed=true;
    public function __construct(private Connection $db, public TenantId $tenant) {}
    public function authorize(SessionId $session,string $purpose):HostIdentity
    {
        $this->db->assertIdle();$this->tenant->assertSame($session->tenant);
        if(!$this->allowed || !in_array($purpose,['chatom.intake','chatom.delivery'],true)) { throw new ContractViolation(ErrorCode::AccessDenied); }
        return new HostIdentity($this->tenant,new Ref($this->tenant,'viewer'),null,new Ref($this->tenant,'authorization'),'policy-1',
            new Ref($this->tenant,'subject'),new Ref($this->tenant,'consent'));
    }
}

/** Real native repositories, real encrypted temporary payloads; scripted destination/host boundary. */
final class Fixture
{
    public IsolatedDatabase $database;
    public IsolatedDatabase $ownerDatabase;
    public string $schema;
    public string $vaultDirectory;
    public RepositoryContext $env;
    public Host $host;
    public ExecutionContext $context;
    public CanonicalHasher $hasher;
    public DeliveryRepository $store;
    public PostgresDelivery $delivery;
    public ScriptedDestination $destination;
    public PayloadRepository $payloads;
    public InvocationPayloadStore $vault;
    public Repository $graph;
    public Invalidation $invalidation;
    public TrustedInput $input;
    public ContextSnapshot $snapshot;
    public \AgenToolsPlatform\Core\Common\AcceptedTurn $ack;
    private bool $owns;
    public function __construct(?string $schema=null,string $tenant='tenant-a',int $retentionSeconds=3600)
    {
        $this->database=new IsolatedDatabase();$this->owns=$schema===null;
        $this->schema=$schema??'p3_'.bin2hex(random_bytes(12));
        if($this->owns) {
            $this->database->createSchema($this->schema);
            (new Migrator($this->database->pdo,$this->schema))->migrate();
            \AgenToolsPlatform\Postgres\Summaries\Schema::install($this->database->pdo,$this->schema);
            $this->database->pdo->exec(file_get_contents(__DIR__.'/owner.sql'));
        }
        $db=new Connection($this->database->pdo,$this->schema);
        $this->hasher=new CanonicalHasher(new JcsAdapter());$t=new TenantId($tenant);
        $this->host=new Host($db,$t);
        $revision=new class implements ContextRevision { public function current(ExecutionContext $context,Ref $snapshot):int { return 1; } };
        $this->env=new RepositoryContext($db,$this->hasher,$this->host,$revision);
        $this->context=ExecutionContext::fromHost($this->host,new SessionId($t,'conversation-1'),'chatom.delivery',new Correlation('trace-1','request-1'));
        $pin=$this->hasher->hash($t,'fixture-pin','code-only');
        (new SessionRepository($this->env))->bind($this->context,new SessionBinding($this->context->session,new InstanceId($t,'assistant-1'),
            $this->ref('code-1'),$pin,$pin,$this->ref('bundle-1'),$pin,$this->ref('conversation-1')));
        $this->store=new DeliveryRepository($this->env,function(ExecutionContext $context,Ref $source,string $operation):void {
            $this->env->authorize($context);$this->ref('chatom:source:msg-1')->assertSame($source);
            if(!$this->host->sourceAllowed && $operation!=='close') { throw new ContractViolation(ErrorCode::AccessDenied); }
        });
        $this->input=new TrustedInput($this->context,new SourceIdentity(new InstanceId($t,'assistant-1'),'chatom.initial.v1','msg-1'),
            new InputPayload($this->ref('input:msg-1'),$this->hasher->hash($t,'source-content','Synthetic source, held only by destination'),
                $this->ref('conversation-1'),$this->ref('chatom:message:msg-1')));
        $this->snapshot=new ContextSnapshot($this->ref('snapshot-1'),1,$pin,$this->context->scopeHash($this->hasher));
        $this->ack=(new Incoming($this->env,$this->store))->accept($this->input,$this->snapshot,$this->ref('chatom:source:msg-1'),1);
        $this->ownerDatabase=new IsolatedDatabase();
        $ownerDb=new Connection($this->ownerDatabase->pdo,$this->schema);
        $ownerDb->execute('INSERT INTO '.$ownerDb->table('fixture_membership').'(tenant_id,destination) VALUES (:tenant,:destination) ON CONFLICT DO NOTHING',
            ['tenant'=>$tenant,'destination'=>'chatom:destination:conversation-1']);
        $this->destination=new ScriptedDestination($ownerDb,$this->env);
        $this->vaultDirectory=sys_get_temp_dir().'/agentools-p102-'.$this->schema;
        if(!is_dir($this->vaultDirectory)) { mkdir($this->vaultDirectory,0700); }
        $encrypted=new FileEncryptedPayloadStore($this->vaultDirectory,static fn(Ref $key):string=>str_repeat('k',32));
        $policy=new class($this) implements PayloadPolicy {
            public function __construct(private Fixture $f) {}
            public function authorize(ExecutionContext $context,PayloadDescriptor $payload,string $operation):Ref {
                $this->f->env->authorize($context);return new Ref($context->session->tenant,'payload-policy-1');
            }
        };
        $this->payloads=new PayloadRepository($this->env,$policy,$encrypted);
        $this->vault=new InvocationPayloadStore($this->env,$this->payloads,$encrypted,$this->ref('test-key-1'),'private',$retentionSeconds);
        $this->delivery=new PostgresDelivery($this->store,$this->vault,$this->destination,
            static function(ExecutionContext $context,string $key,string $reason):void {},200);
        $authority=new class($this) implements Authority {
            public function __construct(private Fixture $f) {}
            public function authorize(ExecutionContext $context,Ref $scope,string $operation):void { $this->f->env->authorize($context);$context->session->tenant->assertSame($scope->tenant); }
            public function acceptsState(ExecutionContext $context,?WorkingState $before,WorkingState $next):void { throw new \LogicException('Not used by delivery fixture'); }
            public function acceptsSummary(ExecutionContext $context,SummaryVersion $summary):void { throw new \LogicException('Not used by delivery fixture'); }
        };
        $this->graph=new Repository($this->env,$authority);
        $this->invalidation=new Invalidation($this->store,$this->graph,$this->hasher,function(ExecutionContext $context,Ref $source,MessageRef $message,string $reason):void {
            $this->env->authorize($context);$this->ref('chatom:source:msg-1')->assertSame($source);$this->ref('chatom:message:msg-1')->assertSame($message->ref);
        });
    }
    public function ref(string $id):Ref { return new Ref($this->host->tenant,$id); }
    public function stage(string $text='Synthetic answer',int $version=1,?string $key=null):DeliverySubmission
    {
        $bytes=json_encode(['display_text'=>$text,'citations'=>[],'artifact_refs'=>[],'action_refs'=>[],
            'response_version'=>$version,'supersedes'=>$version>1?$this->ref('response:v'.($version-1))->toArray():null],JSON_THROW_ON_ERROR);
        $hash=new \AgenToolsPlatform\Core\Common\PayloadHash('sha-256:'.hash('sha256',$bytes));
        $payload=$this->ref('payload:'.substr($hash->value,8));
        $this->vault->put($this->context,$payload,$hash,$bytes);
        $destination=$this->ref('chatom:destination:conversation-1');
        $key??=(new IdentityKeys($this->hasher))->delivery($this->ack->runId,$version,$destination)->value;
        $intent=new DeliverySubmission($key,$this->ack->runId,$this->ref('response:v'.$version),$version,$payload,$hash,$destination,
            $this->ref('chatom:source:msg-1'),1,'policy-1',$hash);
        $this->delivery->submit($this->context,$intent);
        // Seed the completed-generation precondition, not a claim that this fixture ran a provider.
        $this->env->db->execute('UPDATE '.$this->env->db->table('runs').' SET version=version+1,status=\'completed\',terminal_code=\'completed\'
            WHERE tenant_id=:tenant AND id=:run AND status<>\'completed\'',['tenant'=>$this->host->tenant->value,'run'=>$this->ack->runId->value]);
        return $intent;
    }
    public function count(string $table):int { return (int)$this->env->db->execute('SELECT count(*) FROM '.$this->env->db->table($table))->fetchColumn(); }
    public function permission(string $field,bool|string $value):void
    {
        if(!in_array($field,['allowed','visible','held','relay'],true)) { throw new \InvalidArgumentException('fixture field'); }
        $this->env->db->execute('UPDATE '.$this->env->db->table('fixture_membership').' SET '.$field.'=:value WHERE tenant_id=:tenant',
            ['value'=>is_bool($value)?($value?'true':'false'):$value,'tenant'=>$this->host->tenant->value]);
    }
    public function message():MessageRef
    {
        return new MessageRef($this->ref('chatom:message:msg-1'),$this->ref('conversation-1'),1,'1',
            $this->hasher->hash($this->host->tenant,'history-message','Synthetic source, held only by destination'));
    }
    public function close():void
    {
        if(!$this->owns) { return; }
        $this->database->dropSchema($this->schema);
        foreach(glob($this->vaultDirectory.'/*')?:[] as $path) { unlink($path); }
        rmdir($this->vaultDirectory);$this->owns=false;
    }
}
