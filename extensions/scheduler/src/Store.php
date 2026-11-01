<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Scheduler;

use AgenToolsPlatform\Core\Common\{AcceptedTurn, ContractViolation, ErrorCode, IdentityKeys, InputPayload, IntegrityConflict, PayloadHash, Ref, RunId, SourceIdentity, TurnId, UtcInstant};
use AgenToolsPlatform\Core\Context\ContextSnapshot;
use AgenToolsPlatform\Core\Scheduling\SchedulePort;
use AgenToolsPlatform\Core\Security\{ExecutionContext, TrustedInput};
use AgenToolsPlatform\Laravel\Managed\{HostBindings, ProductionBinding};
use AgenToolsPlatform\Postgres\Inbox\IntakeRepository;
use AgenToolsPlatform\Postgres\Runtime\{Connection, RepositoryContext};

/** Durable trigger metadata. Ordinary intake owns Turn/Run/outbox and its transaction. */
final readonly class Store implements SchedulePort, ProductionBinding
{
    public function __construct(public RepositoryContext $environment, public Authority $authority,
        private ?\Closure $afterInsert = null) {}

    public function assertProduction(): void
    {
        HostBindings::assertProductionService($this->authority);
        if ($this->afterInsert !== null) { throw new \LogicException('Production scheduler refuses fault probes'); }
    }

    public function register(Schedule $schedule, ExecutionContext $context): void
    {
        $this->authorize($context, $schedule, 'configure');
        $db = $this->environment->db; $hash = $this->hash($schedule);
        $db->transaction(function () use ($db, $schedule, $hash): void {
            $db->execute('INSERT INTO '.$db->table('scheduler_versions').' VALUES (:tenant,:id,:version,:hash,CAST(:config AS jsonb)) ON CONFLICT DO NOTHING',
                $this->key($schedule->id) + ['version'=>$schedule->version,'hash'=>$hash->value,'config'=>json_encode($schedule->toArray(), JSON_THROW_ON_ERROR)]);
            $saved = $this->version($schedule->id, $schedule->version);
            $this->hash($saved)->assertSame($hash);
            $db->execute('INSERT INTO '.$db->table('scheduler_state').' (tenant_id,schedule_id,current_version) VALUES (:tenant,:id,:version) ON CONFLICT DO NOTHING',
                $this->key($schedule->id) + ['version'=>$schedule->version]);
            $state = $db->row('SELECT current_version FROM '.$db->table('scheduler_state').' WHERE tenant_id=:tenant AND schedule_id=:id FOR UPDATE', $this->key($schedule->id))
                ?? throw new InspectionFailure('schedule_missing');
            $current = Connection::number($state,'current_version');
            if ($current > $schedule->version) { throw new IntegrityConflict('Schedule version cannot move backwards'); }
            if ($current < $schedule->version) {
                // Historical versions/occurrences remain immutable; unaccepted older batch slots cease eligibility.
                $db->execute('UPDATE '.$db->table('scheduler_state').' SET current_version=:version,active_batch=NULL WHERE tenant_id=:tenant AND schedule_id=:id',
                    $this->key($schedule->id) + ['version'=>$schedule->version]);
            }
        });
    }

    public function stop(Ref $id, ExecutionContext $context): void
    {
        $schedule = $this->current($id); $this->authorize($context, $schedule, 'stop');
        $this->environment->db->execute('UPDATE '.$this->environment->db->table('scheduler_state').' SET enabled=false WHERE tenant_id=:tenant AND schedule_id=:id', $this->key($id));
    }

    public function plan(Ref $id, ExecutionContext $context, UtcInstant $through): Batch
    {
        $schedule = $this->current($id); $this->authorize($context, $schedule, 'scan');
        if (\AgenToolsPlatform\Core\Models\Time::compare($through,$this->authority->now()) > 0) { throw new ContractViolation(ErrorCode::InvalidInput); }
        $db = $this->environment->db;
        return $db->transaction(function () use ($db, $id, $schedule, $through): Batch {
            $state = $db->row('SELECT current_version,enabled,active_batch,
                to_char(cursor_at AT TIME ZONE \'UTC\',\'YYYY-MM-DD"T"HH24:MI:SS.US"Z"\') AS cursor_at
                FROM '.$db->table('scheduler_state').' WHERE tenant_id=:tenant AND schedule_id=:id FOR UPDATE', $this->key($id))
                ?? throw new InspectionFailure('schedule_missing');
            if (Connection::number($state,'current_version') !== $schedule->version) { throw new ContractViolation(ErrorCode::OptimisticConflict); }
            if ($state['enabled'] !== true) { return new Batch($schedule, []); }
            if ($state['active_batch'] !== null) {
                $batch = $db->row('SELECT slots FROM '.$db->table('scheduler_batches').' WHERE tenant_id=:tenant AND id=:batch',
                    ['tenant'=>$id->tenant->value,'batch'=>Connection::text($state,'active_batch')]) ?? throw new InspectionFailure('batch_missing');
                return new Batch($schedule, Codec::slots(Connection::text($batch,'slots')));
            }
            $after = $state['cursor_at'] === null
                ? new UtcInstant((new \DateTimeImmutable($schedule->startsAt->value))->modify('-1 microsecond')->format('Y-m-d\TH:i:s.u\Z'))
                : new UtcInstant(Connection::text($state,'cursor_at'));
            if (new \DateTimeImmutable($through->value) <= new \DateTimeImmutable($after->value)) {
                $previous = $db->row('SELECT slots FROM '.$db->table('scheduler_batches').'
                    WHERE tenant_id=:tenant AND schedule_id=:id AND config_version=:version AND through_at=CAST(:through AS timestamptz)',
                    $this->key($id) + ['version'=>$schedule->version,'through'=>$through->value]);
                return new Batch($schedule,$previous === null ? [] : Codec::slots(Connection::text($previous,'slots')));
            }
            $slots = (new Planner())->due($schedule, $after, $through);
            $batchId = $this->environment->hasher->hash($id->tenant,'schedule-batch',[$id->toArray(),$schedule->version,$after->value,$through->value])->value;
            if ($slots !== []) {
                $db->execute('INSERT INTO '.$db->table('scheduler_batches').' VALUES (:tenant,:id,:batch,:version,:through,CAST(:slots AS jsonb))',
                    $this->key($id) + ['batch'=>$batchId,'version'=>$schedule->version,'through'=>$through->value,
                        'slots'=>json_encode(array_map(static fn (UtcInstant $s): string => $s->value, $slots), JSON_THROW_ON_ERROR)]);
            }
            $db->execute('UPDATE '.$db->table('scheduler_state').' SET cursor_at=:through,active_batch=:batch WHERE tenant_id=:tenant AND schedule_id=:id',
                $this->key($id) + ['through'=>$through->value,'batch'=>$slots === [] ? null : $batchId]);
            return new Batch($schedule, $slots);
        });
    }

    public function accept(Ref $schedule, int $configVersion, UtcInstant $plannedAt, TrustedInput $input, ContextSnapshot $snapshot): AcceptedTurn
    {
        $config = $this->version($schedule, $configVersion);
        $this->authorize($input->context, $config, 'accept');
        $source = $this->source($config, $plannedAt);
        if ($input->source->toArray() !== $source->toArray()
            || $input->payload->inputRef->value !== $this->inputRef($config, $plannedAt)->value) { throw new IntegrityConflict('Scheduled source/input binding conflict'); }
        $this->environment->authorize($input->context, $snapshot);
        $bridge = new IntakeBridge(function (Connection $db, TrustedInput $input) use ($config,$plannedAt): void {
            $this->insertInside($config,$plannedAt,$input);
            if ($this->afterInsert !== null) { ($this->afterInsert)(); }
        });
        return (new IntakeRepository($this->environment, $bridge))->accept($input, $snapshot);
    }

    public function accepted(Schedule $schedule, UtcInstant $planned, ExecutionContext $context): ?AcceptedTurn
    {
        $this->authorize($context, $schedule, 'accept');
        $db = $this->environment->db;
        return $db->transaction(function () use ($db, $schedule, $planned, $context): ?AcceptedTurn {
            $row = $db->row('SELECT *,to_char(planned_at AT TIME ZONE \'UTC\',\'YYYY-MM-DD"T"HH24:MI:SS.US"Z"\') AS planned_utc
                FROM '.$db->table('scheduler_occurrences').'
                WHERE tenant_id=:tenant AND schedule_id=:id AND planned_at=CAST(:planned AS timestamptz)',
                $this->key($schedule->id) + ['planned'=>$planned->value]);
            if ($row === null) { return null; }
            if (Connection::number($row,'config_version') !== $schedule->version) { throw new IntegrityConflict('Schedule version binding conflict'); }
            $state = $db->row('SELECT active_batch FROM '.$db->table('scheduler_state').'
                WHERE tenant_id=:tenant AND schedule_id=:id FOR UPDATE', $this->key($schedule->id)) ?? throw new InspectionFailure('schedule_missing');
            if ($state['active_batch'] !== null) {
                $this->completeBatch($db, $schedule->id, Connection::text($state,'active_batch'));
            }
            $saved = $this->decodeOccurrence($row, $schedule, $context);
            return new AcceptedTurn($saved['input']->source->key($this->environment->hasher),
                $saved['input']->source->payloadHash($saved['input']->payload, $this->environment->hasher),
                $saved['turn'], $saved['run']);
        });
    }

    /** Transaction participant only; called by the ordinary intake admission hook. */
    private function insertInside(Schedule $config, UtcInstant $planned, TrustedInput $input): void
    {
        $db = $this->environment->db;
        $state = $db->row('SELECT enabled,current_version,active_batch FROM '.$db->table('scheduler_state').'
            WHERE tenant_id=:tenant AND schedule_id=:id FOR UPDATE', $this->key($config->id)) ?? throw new InspectionFailure('schedule_missing');
        $hash = $this->environment->hasher->hash($config->id->tenant, 'scheduled-input',
            [$this->hash($config)->value, $planned->value, $input->payload->toArray()]);
        $saved = $db->row('SELECT content_hash FROM '.$db->table('scheduler_occurrences').'
            WHERE tenant_id=:tenant AND schedule_id=:id AND planned_at=CAST(:planned AS timestamptz)', $this->key($config->id) + ['planned'=>$planned->value]);
        if ($saved !== null) {
            $hash->assertSame(new PayloadHash(Connection::text($saved,'content_hash')));
            if ($state['active_batch'] !== null) {
                $this->completeBatch($db, $config->id, Connection::text($state,'active_batch'));
            }
            return;
        }
        if ($state['enabled'] !== true || Connection::number($state,'current_version') !== $config->version || $state['active_batch'] === null) {
            throw new ContractViolation(ErrorCode::Cancelled);
        }
        $batch = $db->row('SELECT slots FROM '.$db->table('scheduler_batches').' WHERE tenant_id=:tenant AND id=:batch',
            ['tenant'=>$config->id->tenant->value,'batch'=>Connection::text($state,'active_batch')]) ?? throw new InspectionFailure('batch_missing');
        $slots = array_map(static fn (UtcInstant $s): string => $s->value, Codec::slots(Connection::text($batch,'slots')));
        if (!in_array($planned->value, $slots, true)) { throw new ContractViolation(ErrorCode::InvalidInput); }
        $ids = new IdentityKeys($this->environment->hasher); $turn = $ids->turn($input->source); $run = $ids->run($turn);
        $db->execute('INSERT INTO '.$db->table('scheduler_occurrences').'
            (tenant_id,schedule_id,planned_at,id,config_version,timezone,batch_id,content_hash,turn_id,run_id,input_ref,input_hash,conversation_ref,message_ref,utterance_ref)
            VALUES (:tenant,:id,:planned,:occurrence,:version,:zone,:batch,:hash,:turn,:run,:input,:input_hash,:conversation,:message,:utterance)',
            $this->key($config->id) + ['planned'=>$planned->value,'occurrence'=>$this->handle($config,$planned), 'version'=>$config->version,
                'zone'=>$config->timezone,'batch'=>Connection::text($state,'active_batch'),'hash'=>$hash->value,
                'turn'=>$turn->value,'run'=>$run->value,'input'=>$input->payload->inputRef->value,'input_hash'=>$input->payload->contentHash->value,
                'conversation'=>$input->payload->conversationRef?->value,'message'=>$input->payload->messageRef?->value,
                'utterance'=>$input->payload->utteranceRef?->value]);
        $this->completeBatch($db, $config->id, Connection::text($state,'active_batch'));
    }

    private function completeBatch(Connection $db, Ref $scheduleId, string $batchId): void
    {
        $batch = $db->row('SELECT slots FROM '.$db->table('scheduler_batches').' WHERE tenant_id=:tenant AND id=:batch',
            ['tenant'=>$scheduleId->tenant->value,'batch'=>$batchId]) ?? throw new InspectionFailure('batch_missing');
        $slots = Codec::slots(Connection::text($batch,'slots'));
        $count = $db->row('SELECT count(*) AS total FROM '.$db->table('scheduler_occurrences').' WHERE tenant_id=:tenant AND batch_id=:batch',
            ['tenant'=>$scheduleId->tenant->value,'batch'=>$batchId]) ?? throw new InspectionFailure('batch_missing');
        if (Connection::number($count,'total') === count($slots)) {
            $db->execute('UPDATE '.$db->table('scheduler_state').' SET active_batch=NULL WHERE tenant_id=:tenant AND schedule_id=:id', $this->key($scheduleId));
        }
    }

    /** @return array{schedule:Schedule,planned:UtcInstant,input:TrustedInput,turn:TurnId,run:RunId} */
    public function resolve(string $handle, ExecutionContext $context): array
    {
        $this->environment->authorize($context);
        $row = $this->environment->db->row('SELECT *,to_char(planned_at AT TIME ZONE \'UTC\',\'YYYY-MM-DD"T"HH24:MI:SS.US"Z"\') AS planned_utc
            FROM '.$this->environment->db->table('scheduler_occurrences').' WHERE tenant_id=:tenant AND id=:handle',
            ['tenant'=>$context->identity->tenant->value,'handle'=>$handle]) ?? throw new ContractViolation(ErrorCode::AccessDenied);
        $tenant = $context->identity->tenant;
        $schedule = $this->version(new Ref($tenant,Connection::text($row,'schedule_id')), Connection::number($row,'config_version'));
        $this->authorize($context,$schedule,'dispatch');
        return $this->decodeOccurrence($row, $schedule, $context, $handle);
    }

    /**
     * @param array<string,mixed> $row
     * @return array{schedule:Schedule,planned:UtcInstant,input:TrustedInput,turn:TurnId,run:RunId}
     */
    private function decodeOccurrence(array $row, Schedule $schedule, ExecutionContext $context, ?string $expectedHandle = null): array
    {
        $tenant = $context->identity->tenant;
        $planned = new UtcInstant(Connection::text($row,'planned_utc'));
        $payload = new InputPayload(new Ref($tenant,Connection::text($row,'input_ref')), new PayloadHash(Connection::text($row,'input_hash')),
            $row['conversation_ref'] === null ? null : new Ref($tenant,Connection::text($row,'conversation_ref')),
            $row['message_ref'] === null ? null : new Ref($tenant,Connection::text($row,'message_ref')),
            $row['utterance_ref'] === null ? null : new Ref($tenant,Connection::text($row,'utterance_ref')));
        $input = new TrustedInput($context,$this->source($schedule,$planned),$payload);
        $expected = $this->environment->hasher->hash($tenant,'scheduled-input',[$this->hash($schedule)->value,$planned->value,$payload->toArray()]);
        $expected->assertSame(new PayloadHash(Connection::text($row,'content_hash')));
        $ids = new IdentityKeys($this->environment->hasher); $turn = $ids->turn($input->source); $run = $ids->run($turn);
        $handle = $expectedHandle ?? Connection::text($row,'id');
        if ($handle !== $this->handle($schedule,$planned) || $turn->value !== Connection::text($row,'turn_id') || $run->value !== Connection::text($row,'run_id')) {
            throw new IntegrityConflict('Occurrence reference binding conflict');
        }
        return ['schedule'=>$schedule,'planned'=>$planned,'input'=>$input,'turn'=>$turn,'run'=>$run];
    }

    public function source(Schedule $schedule, UtcInstant $planned): SourceIdentity
    {
        return new SourceIdentity($schedule->instance,'scheduler.occurrence',$this->handle($schedule,$planned));
    }
    public function handle(Schedule $schedule, UtcInstant $planned): string
    {
        return 'occurrence:'.substr((new IdentityKeys($this->environment->hasher))->occurrence($schedule->id,$planned)->value,8);
    }
    public function inputRef(Schedule $schedule, UtcInstant $planned): Ref
    {
        return new Ref($schedule->id->tenant,'input:'.$this->handle($schedule,$planned));
    }
    public function authorize(ExecutionContext $context, Schedule $schedule, string $operation): void
    {
        $this->environment->authorize($context); $schedule->assertPrincipal($context);
        $this->authority->authorize($context,$schedule,$operation);
    }
    private function current(Ref $id): Schedule
    {
        $row = $this->environment->db->row('SELECT current_version FROM '.$this->environment->db->table('scheduler_state').' WHERE tenant_id=:tenant AND schedule_id=:id', $this->key($id))
            ?? throw new ContractViolation(ErrorCode::AccessDenied);
        return $this->version($id,Connection::number($row,'current_version'));
    }
    private function version(Ref $id, int $version): Schedule
    {
        $row = $this->environment->db->row('SELECT config,content_hash FROM '.$this->environment->db->table('scheduler_versions').' WHERE tenant_id=:tenant AND schedule_id=:id AND version=:version',
            $this->key($id) + ['version'=>$version]) ?? throw new ContractViolation(ErrorCode::AccessDenied);
        $schedule = Codec::schedule(Connection::text($row,'config'));
        $schedule->id->assertSame($id);
        if ($schedule->version !== $version) { throw new IntegrityConflict('Schedule version binding conflict'); }
        $this->hash($schedule)->assertSame(new PayloadHash(Connection::text($row,'content_hash')));
        return $schedule;
    }
    private function hash(Schedule $schedule): PayloadHash
    {
        return $this->environment->hasher->hash($schedule->id->tenant,'schedule-version',$schedule->toArray());
    }
    /** @return array{tenant:string,id:string} */
    private function key(Ref $id): array { return ['tenant'=>$id->tenant->value,'id'=>$id->value]; }
}
