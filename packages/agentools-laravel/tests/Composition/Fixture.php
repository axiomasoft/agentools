<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Tests\Composition;

use AgenToolsPlatform\Conformance\WriteJourney\Journey;
use AgenToolsPlatform\Core\Actions\{ActionPolicy, ActionAccess, ActionView, InputSchemaValidator};
use AgenToolsPlatform\Core\Actions\Confirmation\Preview;
use AgenToolsPlatform\Core\Budget\{Admission, AdmissionRequest, BudgetPort};
use AgenToolsPlatform\Core\Common\{ContractViolation, DeliveryId, ErrorCode, InputPayload, PayloadHash, Ref, UtcInstant};
use AgenToolsPlatform\Core\Models\{Deployment, JsonObject, ModelRequest, ModelResponse, ModelTransport, ToolProposal};
use AgenToolsPlatform\Core\Profiles\BundleRef;
use AgenToolsPlatform\Core\Runtime\InvocationCheckpoint;
use AgenToolsPlatform\Core\Security\{ExecutionContext, TrustedInput};
use AgenToolsPlatform\Examples\ConfirmedWrite\KeyMaterial;
use AgenToolsPlatform\Examples\DirectAction\Jcs;
use AgenToolsPlatform\Laravel\AgenToolsServiceProvider;
use AgenToolsPlatform\Laravel\Managed\{DeliveryReceipt, Entry, HostAdmission, HostBindings, Input, Work};
use AgenToolsPlatform\Postgres\Payloads\{EncryptedPayloadStore, PayloadHandle};
use AgenToolsPlatform\Postgres\Runtime\{ContextRevision, RunRepository};
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;

final class Trace
{
    /** @var list<string> */
    public array $events = [];
    /** @var array<string,string> */
    public array $state = [];
    /** @var list<array<string,string>> */
    public array $snapshots = [];
    /** @var list<string> */
    public array $requests = [];
    /** @var list<string> */
    public array $decisions = [];
    public ?string $rawFailure = null;
    public ?string $deny = null;
    public bool $forbidden = false;
    public bool $foreignAuthentication = false;
    public bool $foreignDecision = false;
    public bool $revokeAtDisclosure = false;
    public bool $failReset = false;
    public bool $unknownDelivery = false;
    public bool $budgetUnknown = false;
    public bool $cancelAtDeployment = false;
    public ?\Closure $cancel = null;
    public ?\Closure $resetProbe = null;
    public int $resetDenied = 0;
    /** @return list<string> */
    public function observed(): array { return $this->events; }
    public function stage(string $stage): void
    {
        $this->events[] = $stage;
        if ($this->rawFailure === $stage) { throw new \RuntimeException('alpha secret must not escape'); }
        if ($this->deny === $stage) { throw new ContractViolation(ErrorCode::AccessDenied); }
    }
}

final class Host implements HostAdmission
{
    public function __construct(private Journey $j, public Trace $trace) {}
    public function authenticate(Input $input): Ref { $this->trace->stage('auth'); return $this->trace->foreignAuthentication ? new Ref(new \AgenToolsPlatform\Core\Common\TenantId('other'),'foreign:auth') : $this->j->ref('auth:'.$input->ref); }
    public function trusted(Input $input): TrustedInput
    {
        $this->trace->stage('tenant/session');
        $tenant = $this->j->tenant->value;
        foreach (['tenant','actor','credential','profile','policy','cache','payload','diagnostic','evidence'] as $key) {
            $this->trace->state[$key] = $tenant.':'.$key;
        }
        return new TrustedInput($this->j->context, $this->j->source,
            new InputPayload($this->j->ref('source-input:'.$this->j->instance), new PayloadHash('sha-256:'.hash('sha256', Journey::INPUT))));
    }
    public function profileData(TrustedInput $input): BundleRef
    {
        $this->trace->stage('profile/data');
        $this->trace->snapshots[] = $this->trace->state;
        $this->j->current($input->context);
        return $this->j->plan()->bundle;
    }
    public function compile(TrustedInput $input, BundleRef $bundle, Entry $entry): Work
    {
        $this->trace->stage('context');
        if ($entry === Entry::DirectAction) { return Work::action($this->j->composition->submission(context: $input->context)); }
        $this->j->accept();
        $plan = $this->j->plan();
        $this->j->activePlan = $plan;
        $runs = new RunRepository($this->j->environment);
        $run = $runs->get($input->context, $this->j->run) ?? throw new \LogicException('Intake missing');
        if ($run->status->value === 'accepted') {
            $runs->beginPreparation($input->context, $plan->snapshot, $this->j->run, $run->version);
        }
        return Work::invocation($plan, $this->j->claim());
    }
    public function deployment(TrustedInput $input, Work $work): void
    {
        $this->trace->stage('deployment');
        $this->j->current($input->context);
        if ($this->trace->cancelAtDeployment) { ($this->trace->cancel ?? throw new \LogicException('Missing cancellation'))(); }
    }
    public function disclose(TrustedInput $input, InvocationCheckpoint|ActionView|Preview $result): Ref
    {
        $this->trace->stage('disclosure');
        if ($this->trace->revokeAtDisclosure) { throw new ContractViolation(ErrorCode::AccessDenied); }
        if ($result instanceof InvocationCheckpoint && in_array($result->stage(), ['unknown','outbound'], true)) {
            throw new ContractViolation(ErrorCode::InvocationUnknown);
        }
        return $this->j->ref('checked:'.$this->j->instance);
    }
    public function deliver(TrustedInput $input, Ref $checked, Entry $entry): DeliveryReceipt
    {
        $this->trace->stage('delivery');
        $j = $this->j;
        $key = 'delivery:'.$j->instance;
        $j->db->execute('INSERT INTO '.$j->db->table('write_messages').' VALUES (:tenant,:id,:hash,:receipt) ON CONFLICT DO NOTHING',
            ['tenant'=>$j->tenant->value,'id'=>$key,'hash'=>$j->hasher->hash($j->tenant,'checked',$checked->value)->value,'receipt'=>'delivered:'.$key]);
        return new DeliveryReceipt(new DeliveryId($j->tenant,$key), $this->trace->unknownDelivery ? null : $j->ref('delivered:'.$key));
    }
    public function decision(TrustedInput $input,string $stage,string $code): Ref
    {
        $this->trace->decisions[] = $stage.':'.$code;
        return $this->trace->foreignDecision ? new Ref(new \AgenToolsPlatform\Core\Common\TenantId('other'),'foreign:decision') : $this->j->ref('decision:'.$stage.':'.$code);
    }
    public function reset(): void
    {
        $this->trace->state = [];
        $this->trace->events[] = 'reset';
        if ($this->trace->resetProbe !== null) { ($this->trace->resetProbe)(); }
        if ($this->trace->failReset) { throw new \RuntimeException('alpha secret must not escape'); }
    }
}

final readonly class Transport implements ModelTransport
{
    public function __construct(private Journey $j, private Trace $trace) {}
    public function generate(ModelRequest $request, Deployment $deployment): ModelResponse
    {
        $this->trace->stage('model');
        $this->trace->requests[] = json_encode($request,JSON_THROW_ON_ERROR);
        $response = $this->j->provider->generate($request,$deployment);
        if (!$this->trace->forbidden) { return $response; }
        return new ModelResponse($response->invocationId,$deployment,$deployment,'forbidden',\AgenToolsPlatform\Core\Models\FinishReason::ToolProposal,
            proposals:[new ToolProposal($request->invocationId,'bad-tool','secret_resolver',new JsonObject(['secret_ref'=>'alpha:secret']))]);
    }
    public function stream(ModelRequest $request, Deployment $deployment): iterable { throw new \LogicException('Managed delivery is buffered'); }
}

final readonly class Budget implements BudgetPort
{
    public function __construct(private Journey $j, private Trace $trace) {}
    public function status(AdmissionRequest $request): Admission { return $this->trace->budgetUnknown ? new Admission('unknown') : $this->j->budget->status($request); }
    public function admit(AdmissionRequest $request): Admission { $this->trace->stage('budget'); return $this->j->budget->admit($request); }
    public function settle(Ref $reservation,Ref $usageEvent): void { $this->j->budget->settle($reservation,$usageEvent); }
    public function release(Ref $reservation,string $provenNotSentReason): void { throw new \LogicException('No implicit release'); }
    public function renew(Ref $reservation,UtcInstant $deadline): Admission { return $this->j->budget->renew($reservation,$deadline); }
}

final readonly class Policy implements ActionPolicy
{
    public function __construct(private Journey $j, private Trace $trace) {}
    public function current(ExecutionContext $context): ActionAccess { $this->trace->stage('tools/actions'); return $this->j->policy->current($context); }
}

final class Encrypted implements EncryptedPayloadStore
{
    public int $resets = 0;
    public bool $fail = false;
    public function read(ExecutionContext $context,PayloadHandle $handle): string { throw new \LogicException('Fixture only uses encrypted invocation vault'); }
    public function supportsIdempotentPurge(): bool { return false; }
    public function purge(ExecutionContext $context,PayloadHandle $handle,string $operationKey): void { throw new \LogicException('No purge'); }
    public function reset(): void { ++$this->resets; if ($this->fail) { throw new \RuntimeException('alpha secret'); } }
}

final class Fixture
{
    public Application $app;
    public Dispatcher $events;
    public IsolatedDatabase $database;
    public string $schema;
    public Trace $trace;
    public ?HostBindings $last = null;
    public ?Journey $journey = null;
    public Encrypted $encrypted;
    public string $tenant = 'alpha';
    public string $instance = 'input-1';
    public int $factories = 0;
    public function __construct()
    {
        $this->database = new IsolatedDatabase();
        $this->schema = 'p3_'.bin2hex(random_bytes(12));
        Journey::install($this->database,$this->schema);
        $this->app = new Application(dirname(__DIR__,2));
        $this->events = new Dispatcher($this->app);
        $this->app->instance('events',$this->events);
        $this->app->instance('env','testing');
        $this->app->instance('agentools.testing',true);
        $provider = new AgenToolsServiceProvider($this->app);
        $provider->register(); $provider->boot($this->events);
        $this->trace = new Trace();
        $this->encrypted = new Encrypted();
        $this->app->scoped(HostBindings::class, fn (): HostBindings => $this->bindings());
    }
    public function bindings(): HostBindings
    {
        // Journey ports contain cycles. Bound collection keeps focused class runs below PG connection limits.
        gc_collect_cycles();
        ++$this->factories;
        $j = new Journey(new IsolatedDatabase(),$this->schema,$this->tenant,$this->instance);
        $this->journey = $j;
        $keys = new KeyMaterial(str_repeat('s',32),str_repeat('e',32));
        $revisions = new class implements ContextRevision { public function current(ExecutionContext $context,Ref $snapshot): int { return 1; } };
        $schemas = new class implements InputSchemaValidator {
            public function assertValid(string $schemaJson,string $inputJson): void {
                $value = json_decode($inputJson,true,512,JSON_THROW_ON_ERROR);
                if (!is_array($value) || !is_string($value['recipient']??null) || !is_string($value['document']??null) || !is_int($value['amount']??null)) {
                    throw new ContractViolation(ErrorCode::InvalidInput);
                }
            }
        };
        return $this->last = new HostBindings($j->db,(new IsolatedDatabase())->pdo,$this->schema,$j->hasher,new Jcs(),$j->composition->host,
            $revisions,$j->composition->registry,new Policy($j,$this->trace),$schemas,$j->composition->host,$keys,$keys,$j->composition->host,
            new Transport($j,$this->trace),new Budget($j,$this->trace),$j->policy,$j->vault,$this->encrypted,new Host($j,$this->trace));
    }
    public function current(): Journey { return $this->journey ?? throw new \LogicException('No active fixture'); }
    public function close(): void
    {
        $this->database->dropSchema($this->schema);
        $this->journey = null;
        $this->last = null;
        $this->trace->cancel = null;
        $this->trace->resetProbe = null;
        $this->app->flush();
        \Illuminate\Console\Application::forgetBootstrappers();
        gc_collect_cycles();
    }
}
