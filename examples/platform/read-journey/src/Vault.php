<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\ReadJourney;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, IntegrityConflict, PayloadHash, Ref};
use AgenToolsPlatform\Core\Runtime\InvocationPayloads;
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Runtime\Connection;

/** Explicit synthetic host vault with authenticated encryption, ACL and TTL; no production default. */
final readonly class Vault implements InvocationPayloads
{
    public function __construct(private SupportJourney $journey) {}
    public function put(ExecutionContext $context, Ref $key, PayloadHash $hash, string $json): void
    {
        $j=$this->journey; $j->idle(); $context->assertCurrent($j->host); $key->tenant->assertSame($context->session->tenant);
        $hash->assertSame(new PayloadHash('sha-256:' . hash('sha256',$json)));
        $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $sealed=base64_encode($nonce.sodium_crypto_secretbox($json,$nonce,$j->vaultKey()));
        $j->db->transaction(function () use($j,$context,$key,$hash,$sealed): void {
            $j->db->execute('INSERT INTO '.$j->db->table('demo_vault').'(tenant_id,id,body,hash,expires_at) VALUES (:tenant,:id,:body,:hash,clock_timestamp()+interval \'1 day\') ON CONFLICT DO NOTHING',
                ['tenant'=>$key->tenant->value,'id'=>$key->value,'body'=>$sealed,'hash'=>$hash->value]);
            $saved=$j->db->row('SELECT hash FROM '.$j->db->table('demo_vault').' WHERE tenant_id=:tenant AND id=:id', ['tenant'=>$key->tenant->value,'id'=>$key->value]);
            if($saved===null || Connection::text($saved,'hash')!==$hash->value){throw new IntegrityConflict('Immutable host payload conflict');}
            $j->db->execute('INSERT INTO '.$j->db->table('payload_refs').'(tenant_id,id,session_id,binding_hash,content_hash,encrypted_ref,encryption_key_ref,classification,purpose,retention_until)
                VALUES (:tenant,:id,:session,:binding,:hash,:encrypted,\'host-key\',\'private\',\'support.read\',clock_timestamp()+interval \'1 day\') ON CONFLICT DO NOTHING',
                ['tenant'=>$key->tenant->value,'id'=>$key->value,'session'=>$context->session->value,'binding'=>substr($hash->value,8),'hash'=>substr($hash->value,8),'encrypted'=>'host-vault:'.$key->value]);
            $binding=$j->db->row('SELECT session_id,content_hash,purged_at,purge_pending_at FROM '.$j->db->table('payload_refs').' WHERE tenant_id=:tenant AND id=:id',
                ['tenant'=>$key->tenant->value,'id'=>$key->value]);
            if($binding===null || $binding['session_id']!==$context->session->value || $binding['content_hash']!==substr($hash->value,8)
                || $binding['purged_at']!==null || $binding['purge_pending_at']!==null){throw new IntegrityConflict('Host payload session/binding conflict');}

        });
        $j->boundary(str_ends_with($key->value,':accepted')?'payload':'vault');
    }
    public function find(ExecutionContext $context, Ref $key): ?string
    {
        $j=$this->journey; $j->idle();$context->assertCurrent($j->host);$context->session->tenant->assertSame($key->tenant);
        $row=$j->db->row('SELECT body,hash,expires_at>clock_timestamp() AS readable FROM '.$j->db->table('demo_vault').' WHERE tenant_id=:tenant AND id=:id', ['tenant'=>$key->tenant->value,'id'=>$key->value]);
        if($row===null){return null;}
        $binding=$j->db->row('SELECT session_id,purpose,purged_at,purge_pending_at,retention_until>clock_timestamp() AS retained FROM '.$j->db->table('payload_refs').' WHERE tenant_id=:tenant AND id=:id',
            ['tenant'=>$key->tenant->value,'id'=>$key->value]);
        if($binding===null || $binding['session_id']!==$context->session->value || $binding['purpose']!==$context->purpose || $binding['purged_at']!==null
            || $binding['purge_pending_at']!==null || $binding['retained']!==true){throw new ContractViolation(ErrorCode::AccessDenied);}

        if($row['readable']!==true){throw new ContractViolation(ErrorCode::AccessDenied);}
        $sealed=base64_decode(Connection::text($row,'body'),true);
        if($sealed===false){throw new IntegrityConflict('Corrupt host payload');}
        $json=sodium_crypto_secretbox_open(substr($sealed,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),substr($sealed,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$j->vaultKey());
        if($json===false || 'sha-256:'.hash('sha256',$json)!==Connection::text($row,'hash')){throw new IntegrityConflict('Corrupt host payload');}
        return $json;
    }
    public function get(ExecutionContext $context, Ref $key): string { return $this->find($context,$key) ?? throw new ContractViolation(ErrorCode::AccessDenied); }
}
