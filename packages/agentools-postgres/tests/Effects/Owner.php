<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Tests\Effects;

use AgenToolsPlatform\Core\Actions\Effects\{LookupResult, LookupStatus, OwnerPort, Request, Strategy};
use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, IntegrityConflict, Ref};
use AgenToolsPlatform\Postgres\Runtime\Connection;

/** Durable synthetic owner, on a different connection, with atomic key/hash enforcement. */
final class Owner implements OwnerPort
{
    public int $calls = 0;
    public int $lookups = 0;
    public bool $loseResponse = false;
    public bool $forbidWrites = false;
    public ?LookupStatus $lookupStatus = null;
    public LookupStatus $executeStatus = LookupStatus::Succeeded;

    public function __construct(private Connection $owner, private Connection $local, private Strategy $capability = Strategy::NativeKey) {}
    public function namespace(): string { return 'test.durable-owner'; }
    public function strategy(): Strategy { return $this->capability; }

    public function execute(Request $request): LookupResult
    {
        ++$this->calls;
        if ($this->forbidWrites) { throw new \LogicException('Any replay is forbidden'); }
        $this->local->assertIdle();
        $result = $this->owner->transaction(function () use ($request): LookupResult {
            $db = $this->owner;
            $intent = $db->row('SELECT * FROM '.$db->table('effect_intents').' WHERE tenant_id=:tenant AND owner_operation_key=:key',
                ['tenant'=>$request->action->tenant->value,'key'=>$request->operationKey]);
            if ($intent === null || Connection::text($intent,'request_hash') !== substr($request->requestHash->value,8)
                || Connection::text($intent,'request_ref') !== $request->requestRef->value
                || Connection::number($intent,'claim_epoch') !== $request->requestedEpoch) { throw new IntegrityConflict('Intent must commit before owner IO'); }
            if (!$this->allowed($request)) { throw new ContractViolation(ErrorCode::AccessDenied); }
            $db->execute('INSERT INTO '.$db->table('example_owner_writes').' VALUES (:tenant,:key,:hash,CAST(:payload AS jsonb))
                ON CONFLICT (tenant_id,operation_key) DO NOTHING', ['tenant'=>$request->action->tenant->value,
                    'key'=>$request->operationKey,'hash'=>$request->requestHash->value,'payload'=>json_encode(['outcome'=>$this->executeStatus->value],JSON_THROW_ON_ERROR)]);
            return $this->found($request);
        });
        if ($this->loseResponse) { throw new \RuntimeException('Owner committed; response lost'); }
        return $result;
    }

    public function lookup(Request $request): LookupResult
    {
        ++$this->lookups;
        $this->local->assertIdle();
        return $this->owner->transaction(function () use ($request): LookupResult {
            if (!$this->allowed($request)) { throw new ContractViolation(ErrorCode::AccessDenied); }
            if ($this->lookupStatus !== null) { return $this->result($request,$this->lookupStatus); }
            if ($this->capability === Strategy::Unsupported) { return $this->result($request,LookupStatus::Unsupported); }
            return $this->found($request);
        });
    }

    private function allowed(Request $request): bool
    {
        $row = $this->owner->row('SELECT allowed FROM '.$this->owner->table('example_owner_permissions').' WHERE tenant_id=:tenant AND actor_ref=:actor FOR SHARE',
            ['tenant'=>$request->action->tenant->value,'actor'=>$request->context->identity->actorRef->value]);
        return $row !== null && $row['allowed'] === true;
    }

    private function found(Request $request): LookupResult
    {
        $row = $this->owner->row('SELECT request_hash,payload->>\'outcome\' AS outcome FROM '.$this->owner->table('example_owner_writes').' WHERE tenant_id=:tenant AND operation_key=:key',
            ['tenant'=>$request->action->tenant->value,'key'=>$request->operationKey]);
        if ($row === null) { return $this->result($request,LookupStatus::Absent); }
        if (Connection::text($row,'request_hash') !== $request->requestHash->value) { throw new IntegrityConflict('Owner hash conflict'); }
        return $this->result($request,LookupStatus::from(Connection::text($row,'outcome')));
    }

    public function result(Request $request, LookupStatus $status): LookupResult
    {
        return new LookupResult($status,new Ref($request->action->tenant,$request->operationKey),$this->namespace(),$request->requestHash,
            in_array($status,[LookupStatus::Succeeded,LookupStatus::Failed,LookupStatus::Absent],true)
                ? new Ref($request->action->tenant,($status === LookupStatus::Absent ? 'owner:absence:' : 'owner:receipt:').$request->operationKey) : null);
    }
}
