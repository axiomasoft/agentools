<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Definitions;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, InstanceId, IntegrityConflict, Ref};
use AgenToolsPlatform\Core\Profiles\BundleRef;
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Runtime\{Connection, RepositoryContext};

/** Only assignments/drafts and immutable technical receipts. No definition content is loaded from DB. */
final readonly class PostgresInstances
{
    public function __construct(private RepositoryContext $environment,private Registry $registry,private InstanceAuthority $authority) {}

    /** Explicit opt-in setup by the schema owner, never an entrypoint side effect. */
    public static function install(Connection $db): void
    {
        $sql=file_get_contents(__DIR__.'/schema.sql');
        if ($sql===false) { throw new \RuntimeException('Missing definitions schema'); }
        $hash=hash('sha256',$sql);
        $sql=str_replace(['{{schema_versions}}','{{instances}}','{{mutations}}','{{pins}}'],
            [$db->table('assistant_schema_versions'),$db->table('assistant_assignments'),$db->table('assistant_mutations'),$db->table('assistant_pins')],$sql);
        $db->transaction(static function () use ($db,$sql,$hash): void {
            foreach (explode(';',$sql) as $statement) { if (trim($statement)!=='') { $db->execute($statement); } }
            $db->execute('INSERT INTO '.$db->table('assistant_schema_versions').' VALUES (1,:hash) ON CONFLICT DO NOTHING',['hash'=>$hash]);
            $row=$db->row('SELECT source_hash FROM '.$db->table('assistant_schema_versions').' WHERE revision=1');
            if ($row===null || Connection::text($row,'source_hash')!==$hash) { throw new IntegrityConflict('Definitions schema revision conflict'); }
        });
    }

    /** @phpstan-impure Stateful persistence and current owner permission checks. */
    public function create(ExecutionContext $context,Ref $command,InstanceId $id,Reference $definition,BundleRef $bundle): Instance
    {
        $this->authorize($context,$id,'create');
        $entry=$this->registry->resolve($definition,$id->tenant);
        $id->tenant->assertSame($bundle->ref->tenant);$this->authority->bundle($context,$entry,$bundle);
        return $this->mutate($context,$command,new Instance($id,1,$definition,$bundle,new DraftSettings()),0,'create');
    }
    /** @phpstan-impure Stateful persistence and current owner permission checks. */
    public function assign(ExecutionContext $context,Ref $command,InstanceId $id,int $expected,BundleRef $bundle): Instance
    {
        $this->authorize($context,$id,'assign');$before=$this->get($context,$id);
        $entry=$this->registry->resolve($before->definition,$id->tenant);$id->tenant->assertSame($bundle->ref->tenant);
        $this->authority->bundle($context,$entry,$bundle);
        return $this->mutate($context,$command,new Instance($id,$expected+1,$before->definition,$bundle,$before->draft),$expected,'assign');
    }
    /** @phpstan-impure Stateful persistence and current owner permission checks. */
    public function draft(ExecutionContext $context,Ref $command,InstanceId $id,int $expected,DraftSettings $draft): Instance
    {
        $this->authorize($context,$id,'draft');$before=$this->get($context,$id);
        $this->registry->resolve($before->definition,$id->tenant)->preview($draft);
        return $this->mutate($context,$command,new Instance($id,$expected+1,$before->definition,$before->bundle,$draft),$expected,'draft');
    }
    /** @phpstan-impure Stateful persistence and current owner permission checks. */
    public function get(ExecutionContext $context,InstanceId $id): Instance
    {
        $this->authorize($context,$id,'read');
        $row=$this->environment->db->row('SELECT state FROM '.$this->environment->db->table('assistant_assignments').' WHERE tenant_id=:tenant AND id=:id',
            ['tenant'=>$id->tenant->value,'id'=>$id->value])??throw new ContractViolation(ErrorCode::CapabilityUnavailable);
        $state=StateCodec::decode(Connection::text($row,'state'),$id->tenant);$id->assertSame($state->id);
        $this->registry->resolve($state->definition,$id->tenant);
        return $state;
    }
    /** Pin before session materialization; a retry recovers the original pin, never latest assignment. */
    public function pin(ExecutionContext $context,InstanceId $id): SessionPin
    {
        $this->authorize($context,$id,'pin');$db=$this->environment->db;
        return $db->transaction(function () use ($db,$context,$id): SessionPin {
            $row=$db->row('SELECT state FROM '.$db->table('assistant_assignments').' WHERE tenant_id=:tenant AND id=:id FOR SHARE',
                ['tenant'=>$id->tenant->value,'id'=>$id->value])??throw new ContractViolation(ErrorCode::CapabilityUnavailable);
            $db->execute('INSERT INTO '.$db->table('assistant_pins').' VALUES (:tenant,:session,:id,CAST(:state AS jsonb)) ON CONFLICT DO NOTHING',
                ['tenant'=>$id->tenant->value,'session'=>$context->session->value,'id'=>$id->value,'state'=>Connection::text($row,'state')]);
            $saved=$db->row('SELECT instance_id,state FROM '.$db->table('assistant_pins').' WHERE tenant_id=:tenant AND session_id=:session',
                ['tenant'=>$id->tenant->value,'session'=>$context->session->value])??throw new IntegrityConflict('Missing instance pin');
            if (Connection::text($saved,'instance_id')!==$id->value) { throw new IntegrityConflict('Session instance retarget refused'); }
            $pin=StateCodec::decode(Connection::text($saved,'state'),$id->tenant);
            $this->registry->resolve($pin->definition,$id->tenant);
            return new SessionPin($pin->id,$pin->definition,$pin->bundle);
        });
    }
    private function authorize(ExecutionContext $context,InstanceId $id,string $operation): void
    {
        $context->identity->tenant->assertSame($id->tenant);$this->environment->authorize($context);
        $this->authority->authorize($context,$id,$operation);
    }
    private function mutate(ExecutionContext $context,Ref $command,Instance $desired,int $expected,string $operation): Instance
    {
        $command->tenant->assertSame($desired->id->tenant);
        $hash=$this->environment->hasher->hash($command->tenant,'assistant-instance-command',[
            'operation'=>$operation,'expected'=>$expected,'instance'=>$desired->id->toArray(),
            'change'=>match ($operation) {
                'assign'=>$desired->bundle->toArray(), 'draft'=>$desired->draft->toArray(),
                default=>json_decode(StateCodec::encode($desired),false,32,JSON_THROW_ON_ERROR),
            },
            'scope'=>$context->scopeHash($this->environment->hasher)->value]);
        $db=$this->environment->db;
        return $db->transaction(function () use ($db,$command,$desired,$expected,$hash,$operation): Instance {
            $db->execute('SELECT pg_advisory_xact_lock(hashtextextended(:key,0))',['key'=>$command->tenant->value.':assistant:'.$command->value]);
            $saved=$db->row('SELECT request_hash,receipt FROM '.$db->table('assistant_mutations').' WHERE tenant_id=:tenant AND id=:id',
                ['tenant'=>$command->tenant->value,'id'=>$command->value]);
            if ($saved!==null) {
                if (Connection::text($saved,'request_hash')!==$hash->value) { throw new IntegrityConflict('Instance command hash conflict'); }
                return StateCodec::decode(Connection::text($saved,'receipt'),$command->tenant);
            }
            $row=$db->row('SELECT revision FROM '.$db->table('assistant_assignments').' WHERE tenant_id=:tenant AND id=:id FOR UPDATE',
                ['tenant'=>$desired->id->tenant->value,'id'=>$desired->id->value]);
            if (($row===null?0:Connection::number($row,'revision'))!==$expected) { throw new ContractViolation(ErrorCode::OptimisticConflict); }
            $encoded=StateCodec::encode($desired);
            if ($operation==='create') {
                $db->execute('INSERT INTO '.$db->table('assistant_assignments').' VALUES (:tenant,:id,:revision,CAST(:state AS jsonb))',
                    ['tenant'=>$desired->id->tenant->value,'id'=>$desired->id->value,'revision'=>$desired->revision,'state'=>$encoded]);
            } else {
                $db->execute('UPDATE '.$db->table('assistant_assignments').' SET revision=:revision,state=CAST(:state AS jsonb) WHERE tenant_id=:tenant AND id=:id',
                    ['tenant'=>$desired->id->tenant->value,'id'=>$desired->id->value,'revision'=>$desired->revision,'state'=>$encoded]);
            }
            $db->execute('INSERT INTO '.$db->table('assistant_mutations').' VALUES (:tenant,:id,:hash,CAST(:receipt AS jsonb))',
                ['tenant'=>$command->tenant->value,'id'=>$command->value,'hash'=>$hash->value,'receipt'=>$encoded]);
            return $desired;
        });
    }
}
