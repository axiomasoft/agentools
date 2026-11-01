<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Scheduler\Tests;

use AgenToolsPlatform\Conformance\WriteJourney\Journey;
use AgenToolsPlatform\Core\Actions\ActionView;
use AgenToolsPlatform\Core\Actions\Confirmation\Preview;
use AgenToolsPlatform\Core\Common\{ContractViolation, DeliveryId, ErrorCode, IdentityKeys, InputPayload, PayloadHash, Ref, UtcInstant};
use AgenToolsPlatform\Core\Models\ModelRequest;
use AgenToolsPlatform\Core\Profiles\BundleRef;
use AgenToolsPlatform\Core\Runtime\{AdvanceLease, InvocationCheckpoint, InvocationPlan};
use AgenToolsPlatform\Core\Security\{ExecutionContext, TrustedInput};
use AgenToolsPlatform\Laravel\Managed\{DeliveryReceipt, Entry, HostAdmission, HostBindings, Input, Work};
use AgenToolsPlatform\Laravel\Tests\Composition\{Fixture as RuntimeFixture, Host, Trace};
use AgenToolsPlatform\Postgres\Claims\{ClaimPolicy, ClaimRepository};
use AgenToolsPlatform\Postgres\Runtime\RunRepository;
use AgenToolsPlatform\Scheduler\{Authority, Prepared, Schedule, ScheduledHost, Schema, Store};

final class HostAuthority implements Authority
{
    public bool $allowed = true;
    public int $resets = 0;
    public string $body = Journey::INPUT;
    public UtcInstant $time;
    /** @var array<string,string> */
    public array $cache = [];
    public function __construct(public Journey $j) { $this->time = new UtcInstant('2026-11-01T12:00:00Z'); }
    public function context(): ExecutionContext { $this->cache['tenant'] = $this->j->tenant->value; return $this->j->context; }
    public function now(): UtcInstant { return $this->time; }
    public function authorize(ExecutionContext $context, Schedule $schedule, string $operation): void
    {
        $this->j->current($context);
        if (!$this->allowed) { throw new ContractViolation(ErrorCode::AccessDenied); }
    }
    public function prepare(ExecutionContext $context, Schedule $schedule, UtcInstant $plannedAt, Ref $inputRef): Prepared
    {
        $hash = new PayloadHash('sha-256:'.hash('sha256',$this->body));
        $this->j->vault->put($context,$inputRef,$hash,$this->body);
        return new Prepared(new InputPayload($inputRef,$hash),$this->j->snapshot('scheduler'));
    }
    public function reset(): void { $this->cache = []; ++$this->resets; }
}

/** Host-specific compilation consumes the scheduled TrustedInput; runtime execution remains unchanged. */
final class RuntimeHost implements HostAdmission
{
    private Host $base;
    public bool $substitute = false;
    public bool $proposal = false;
    public ?string $forcedHandle = null;
    public bool $substituteWork = false;
    public function __construct(private Journey $j, private Trace $trace, private Store $store) { $this->base = new Host($j,$trace); }
    public function authenticate(Input $input): Ref { return $this->base->authenticate($input); }
    public function trusted(Input $input): TrustedInput
    {
        $trusted = $this->base->trusted($input);
        return $this->substitute ? $trusted : $this->store->resolve($this->forcedHandle ?? $input->ref,$trusted->context)['input'];
    }
    public function profileData(TrustedInput $input): BundleRef { return $this->base->profileData($input); }
    public function compile(TrustedInput $input, BundleRef $bundle, Entry $entry): Work
    {
        $this->trace->stage('context'); $j = $this->j;
        $ids = new IdentityKeys($j->hasher); $turn = $ids->turn($input->source); $id = $ids->run($turn);
        $runs = new RunRepository($j->environment); $run = $runs->get($input->context,$id) ?? throw new \LogicException('Missing scheduled run');
        if (in_array($run->status->value,['unresolved','completed','cancelled','failed'],true)) { throw new ContractViolation(ErrorCode::InvocationUnknown); }
        $prototype = $j->plan(!$this->proposal); $snapshot = $j->snapshot('scheduler');
        $step = $this->proposal ? 'proposal' : 'response';
        $request = new ModelRequest($ids->invocation($id,$step,1),$prototype->request->messages,$snapshot->ref,$snapshot->contentHash,
            $prototype->request->credentialRef,$prototype->request->deadline,tools:$prototype->request->tools,generation:$prototype->request->generation);
        $plan = new InvocationPlan($id,$turn,$step,$request,$j->ref('request:'.$request->invocationId->value),
            $prototype->deployment,$snapshot,$bundle,$j->ref('business:'.$input->source->eventId),
            $prototype->destination,$prototype->tools,$prototype->bounds,$prototype->estimatedUnits,null,null,$j->hasher);
        if ($this->substituteWork) {
            $plan = new InvocationPlan($j->run,$j->turn,$step,$request,$plan->requestRef,$plan->deployment,$snapshot,$bundle,
                $plan->businessOperation,$plan->destination,$plan->tools,$plan->bounds,$plan->estimatedUnits,null,null,$j->hasher);
        }
        $j->activePlan = $plan;
        if ($run->status->value === 'accepted') { $run = $runs->beginPreparation($input->context,$snapshot,$id,$run->version); }
        $claim = (new ClaimRepository($j->environment,new ClaimPolicy(300000)))->claim($input->context,$snapshot,$id,$run->version)
            ?? throw new ContractViolation(ErrorCode::StaleClaim);
        return Work::invocation($plan,new AdvanceLease($id,$claim->state->version,$claim->state->fencingEpoch,$claim->owner));
    }
    public function deployment(TrustedInput $input, Work $work): void { $this->base->deployment($input,$work); }
    public function disclose(TrustedInput $input, InvocationCheckpoint|ActionView|Preview $result): Ref { return $this->base->disclose($input,$result); }
    public function deliver(TrustedInput $input, Ref $checked, Entry $entry): DeliveryReceipt
    {
        $this->trace->stage('delivery'); $j = $this->j;
        $key = 'delivery:'.$input->source->eventId;
        $j->db->execute('INSERT INTO '.$j->db->table('write_messages').' VALUES (:tenant,:id,:hash,:receipt) ON CONFLICT DO NOTHING',
            ['tenant'=>$j->tenant->value,'id'=>$key,'hash'=>$j->hasher->hash($j->tenant,'checked',$checked->value)->value,'receipt'=>'delivered:'.$key]);
        return new DeliveryReceipt(new DeliveryId($j->tenant,$key),$j->ref('delivered:'.$key));
    }
    public function decision(TrustedInput $input, string $stage, string $code): Ref { return $this->base->decision($input,$stage,$code); }
    public function reset(): void { $this->base->reset(); }
}

final class Fixture
{
    public RuntimeFixture $runtime;
    public HostBindings $ports;
    public HostAuthority $authority;
    public Store $store;
    public RuntimeHost $host;
    public function __construct()
    {
        $this->runtime = new RuntimeFixture();
        Schema::install($this->runtime->database->pdo,$this->runtime->schema);
        $p = $this->runtime->bindings(); $j = $this->runtime->current();
        $this->authority = new HostAuthority($j); $this->store = new Store($j->environment,$this->authority);
        $this->host = new RuntimeHost($j,$this->runtime->trace,$this->store);
        $this->ports = new HostBindings($p->connection,$p->replayConnection,$p->schema,$p->hasher,$p->json,$p->authorization,
            $p->revisions,$p->registry,$p->policy,$p->schemas,$p->confirmation,$p->keys,$p->sealer,$p->clock,
            $p->transport,$p->budget,$p->guard,$p->payloads,$p->encrypted,new ScheduledHost($this->host,$this->store));
        $this->runtime->app->scoped(HostBindings::class,fn (): HostBindings => $this->ports);
    }
    public function schedule(string $id = 'daily', int $version = 1, string $zone = 'UTC', string $time = '12:00:00',
        string $start = '2026-11-01T00:00:00Z', string $end = '2027-01-01T00:00:00Z', string $misfire = 'catchup',
        string $overlap = 'choose-first', string $gap = 'skip'): Schedule
    {
        $j = $this->runtime->current(); $c = $j->context;
        return new Schedule($j->ref($id),$version,$j->source->instance,$c->session,$c->identity->actorRef,$c->identity->subjectRef,
            $c->purpose,$j->ref('template:daily'),$zone,$time,new UtcInstant($start),new UtcInstant($end),$overlap,$gap,$misfire);
    }
    public function close(): void { $this->runtime->close(); }
}
