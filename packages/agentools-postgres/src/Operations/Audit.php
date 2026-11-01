<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Operations;

use AgenToolsPlatform\Core\Actions\Cancellation\{Authority, Operation};
use AgenToolsPlatform\Core\Common\IntegrityConflict;
use AgenToolsPlatform\Postgres\Runtime\{Connection, RepositoryContext};

/** Immutable command + accepted receipt. Pending intent remains visible after crash; no fabricated failure. */
final readonly class Audit
{
    public function __construct(public RepositoryContext $environment, private Authority $authority) {}

    public function authorize(Operation $command): void
    {
        $this->environment->authorize($command->context);
        $this->authority->authorize($command);
    }

    /** @return array<string,mixed>|null */
    public function begin(Operation $command): ?array
    {
        $this->authorize($command);
        return $this->environment->db->transaction(fn (): ?array=>$this->beginInside($command));
    }

    /** Read-only operator lookup; an ID is never authorization and a missing receipt is pending, not failure.
     * @return array<string,mixed>|null
     */
    public function status(Operation $command): ?array
    {
        $this->authorize($command);
        $db=$this->environment->db;
        $row=$db->row('SELECT request_hash,actor_ref,reason,evidence_ref,created_at,
            floor(extract(epoch FROM clock_timestamp()-created_at))::bigint AS age_seconds
            FROM '.$db->table('operator_operations').' WHERE tenant_id=:tenant AND id=:id',$this->keys($command));
        if ($row===null) { return null; }
        if (Connection::text($row,'request_hash')!==$command->hash->value) { throw new IntegrityConflict('Operational lookup hash conflict'); }
        $receipt=$this->receipt($command);
        return ['state'=>$receipt===null?'pending':'recorded','actor_ref'=>Connection::text($row,'actor_ref'),
            'reason'=>Connection::text($row,'reason'),'evidence_ref'=>$row['evidence_ref'],
            'age_seconds'=>Connection::number($row,'age_seconds'),'result'=>$receipt];
    }

    /**
     * @param \Closure():array<string,mixed> $operation
     * @return array<string,mixed>
     */
    public function local(Operation $command, \Closure $operation): array
    {
        $this->authorize($command);
        return $this->environment->db->transaction(function () use ($command,$operation): array {
            return $this->beginInside($command) ?? $this->completeInside($command,$operation());
        });
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    public function complete(Operation $command, array $result): array
    {
        $this->authorize($command);
        return $this->environment->db->transaction(fn (): array=>$this->completeInside($command,$result));
    }

    /** Caller owns this connection's transaction; no authorization or external IO here.
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    public function completeInside(Operation $command, array $result): array
    {
        ksort($result);
        $saved=$this->beginInside($command);
        $hash=$this->environment->hasher->hash($command->id->tenant,'operator-result',$result)->value;
        if ($saved!==null) {
            if ($this->environment->hasher->hash($command->id->tenant,'operator-result',$saved)->value!==$hash) {
                throw new IntegrityConflict('Accepted operational result is immutable');
            }
            return $saved;
        }
        $db=$this->environment->db;
        $db->execute('INSERT INTO '.$db->table('operator_operation_receipts').' (tenant_id,id,result_hash,result)
            VALUES (:tenant,:id,:hash,CAST(:result AS jsonb))', $this->keys($command)+[
                'hash'=>$hash,'result'=>json_encode((object)$result,JSON_THROW_ON_ERROR)]);
        return $result;
    }

    /** @return array<string,mixed>|null */
    private function beginInside(Operation $command): ?array
    {
        $db=$this->environment->db; $keys=$this->keys($command);
        $db->execute('INSERT INTO '.$db->table('operator_operations').'
            (tenant_id,id,kind,target_ref,actor_ref,session_ref,scope_hash,request_hash,expected_version,expected_epoch,reason,evidence_ref)
            VALUES (:tenant,:id,:kind,:target,:actor,:session,:scope,:hash,:version,:epoch,:reason,:evidence) ON CONFLICT DO NOTHING',
            $keys+['kind'=>$command->kind,'target'=>$command->target->value,'actor'=>$command->context->identity->actorRef->value,
                'session'=>$command->context->session->value,'scope'=>$command->context->scopeHash($this->environment->hasher)->value,
                'hash'=>$command->hash->value,'version'=>$command->expectedVersion,'epoch'=>$command->expectedEpoch,
                'reason'=>$command->reason,'evidence'=>$command->evidence?->value]);
        $row=$db->row('SELECT request_hash FROM '.$db->table('operator_operations').' WHERE tenant_id=:tenant AND id=:id FOR UPDATE',$keys);
        if ($row===null || Connection::text($row,'request_hash')!==$command->hash->value) { throw new IntegrityConflict('Operational identity/hash conflict'); }
        return $this->receipt($command);
    }

    /** @return array<string,mixed>|null */
    private function receipt(Operation $command): ?array
    {
        $db=$this->environment->db;
        $receipt=$db->row('SELECT result,result_hash FROM '.$db->table('operator_operation_receipts').' WHERE tenant_id=:tenant AND id=:id',$this->keys($command));
        if ($receipt===null) { return null; }
        $result=json_decode(Connection::text($receipt,'result'),true,512,JSON_THROW_ON_ERROR);
        if (!is_array($result)) { throw new IntegrityConflict('Invalid operational receipt'); }
        $named=[];
        foreach ($result as $key=>$value) { if (!is_string($key)) { throw new IntegrityConflict('Invalid receipt field'); } $named[$key]=$value; }
        if ($this->environment->hasher->hash($command->id->tenant,'operator-result',$named)->value!==Connection::text($receipt,'result_hash')) {
            throw new IntegrityConflict('Operational receipt digest conflict');
        }
        ksort($named);
        return $named;
    }

    /** @return array{tenant:string,id:string} */
    private function keys(Operation $command): array { return ['tenant'=>$command->id->tenant->value,'id'=>$command->id->value]; }
}
