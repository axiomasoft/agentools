<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Laravel\Operations;

use AgenToolsPlatform\Core\Common\IntegrityConflict;
use AgenToolsPlatform\Postgres\Runtime\{Connection, RepositoryContext};

final readonly class Journal
{
    public function __construct(private RepositoryContext $environment,private Authority $authority) {}
    /** @return array<string,mixed>|null */
    public function begin(Command $c): ?array
    {
        if (in_array($c->verb,['doctor','inspect-run'],true)) { throw new \InvalidArgumentException('Read-only diagnostics cannot write an operation intent'); }
        $this->environment->authorize($c->context); $this->authority->authorize($c);
        $db=$this->environment->db;
        return $db->transaction(function () use ($db,$c): ?array {
            $hash=$this->hash($c);
            $db->execute('INSERT INTO '.$db->table('ops_facade_intents').'
                (tenant_id,id,session_id,actor_ref,request_hash,verb,reason,target_ref,dry_run)
                VALUES (:tenant,:id,:session,:actor,:hash,:verb,:reason,:target,:dry) ON CONFLICT DO NOTHING',
                $this->keys($c)+['session'=>$c->context->session->value,'actor'=>$c->context->identity->actorRef->value,
                    'hash'=>$hash,'verb'=>$c->verb,'reason'=>$c->reason,'target'=>$c->target,'dry'=>$c->apply?'false':'true']);
            $row=$db->row('SELECT request_hash FROM '.$db->table('ops_facade_intents').' WHERE tenant_id=:tenant AND id=:id FOR UPDATE',$this->keys($c));
            if ($row===null || Connection::text($row,'request_hash')!==$hash) { throw new IntegrityConflict('Operation identity conflict'); }
            return $this->receipt($c);
        });
    }
    /** Missing receipt remains unknown after process death.
     * @return array<string,mixed>|null */
    public function status(Command $c): ?array
    {
        $this->environment->authorize($c->context); $this->authority->authorize($c);
        $db=$this->environment->db;
        $row=$db->row('SELECT request_hash FROM '.$db->table('ops_facade_intents').' WHERE tenant_id=:tenant AND id=:id',$this->keys($c));
        if ($row===null) { return null; }
        if (Connection::text($row,'request_hash')!==$this->hash($c)) { throw new IntegrityConflict('Operation identity conflict'); }
        return ['state'=>($receipt=$this->receipt($c))===null?'unknown':'recorded','result'=>$receipt];
    }
    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    public function complete(Command $c,array $result): array
    {
        if (($saved=$this->begin($c))!==null) { return $saved; }
        $this->environment->authorize($c->context); $this->authority->authorize($c);
        $db=$this->environment->db;
        return $db->transaction(function () use ($db,$c,$result): array {
            $hash=$this->environment->hasher->hash($c->id->tenant,'ops-result',$result)->value;
            $db->execute('INSERT INTO '.$db->table('ops_facade_receipts').' (tenant_id,id,result_hash,result)
                VALUES (:tenant,:id,:hash,CAST(:result AS jsonb)) ON CONFLICT DO NOTHING',
                $this->keys($c)+['hash'=>$hash,'result'=>json_encode($result,JSON_THROW_ON_ERROR)]);
            // A concurrent identical command may have obtained the accepted result. Never replace it.
            return $this->receipt($c) ?? throw new IntegrityConflict('Missing operational receipt');
        });
    }
    /** @return array<string,mixed>|null */
    private function receipt(Command $c): ?array
    {
        $db=$this->environment->db;
        $row=$db->row('SELECT result,result_hash FROM '.$db->table('ops_facade_receipts').' WHERE tenant_id=:tenant AND id=:id',$this->keys($c));
        if ($row===null) { return null; }
        $value=json_decode(Connection::text($row,'result'),true,32,JSON_THROW_ON_ERROR);
        if (!is_array($value)) { throw new IntegrityConflict('Corrupt operational receipt'); }
        $result=[];
        foreach ($value as $key=>$item) {
            if (!is_string($key)) { throw new IntegrityConflict('Corrupt operational receipt'); } $result[$key]=$item;
        }
        if ($this->environment->hasher->hash($c->id->tenant,'ops-result',$result)->value!==Connection::text($row,'result_hash')) {
            throw new IntegrityConflict('Operational receipt hash conflict');
        }
        return $result;
    }
    private function hash(Command $c): string
    {
        return $this->environment->hasher->hash($c->id->tenant,'ops-command',[
            'input'=>$c->identity(),'scope'=>$c->context->scopeHash($this->environment->hasher)->value])->value;
    }
    /** @return array{tenant:string,id:string} */
    private function keys(Command $c): array { return ['tenant'=>$c->id->tenant->value,'id'=>$c->id->value]; }
}
