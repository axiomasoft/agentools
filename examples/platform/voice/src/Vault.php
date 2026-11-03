<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\Voice;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, IntegrityConflict, PayloadHash, Ref};
use AgenToolsPlatform\Core\Runtime\InvocationPayloads;
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Runtime\Connection as C;

/** Isolated example vault. The ephemeral key is scoped to this runnable host, never a production key store. */
final readonly class Vault implements InvocationPayloads
{
    public function __construct(private Journey $j,private string $key) {}
    public function put(ExecutionContext $context,Ref $key,PayloadHash $hash,string $json): void
    {
        $context->assertCurrent($this->j->host);$context->session->tenant->assertSame($key->tenant);
        $hash->assertSame(new PayloadHash('sha-256:'.hash('sha256',$json)));
        $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);$sealed=base64_encode($nonce.sodium_crypto_secretbox($json,$nonce,$this->key));
        $db=$this->j->db;$p=['tenant'=>$key->tenant->value,'id'=>$key->value];
        $db->execute('INSERT INTO '.$db->table('voice_demo_vault').' VALUES(:tenant,:id,:hash,:sealed,:scope) ON CONFLICT DO NOTHING',
            $p+['hash'=>$hash->value,'sealed'=>$sealed,'scope'=>$context->scopeHash($this->j->hasher)->value]);
        if ($this->get($context,$key)!==$json) { throw new IntegrityConflict('Example immutable payload conflict'); }
        $db->execute('INSERT INTO '.$db->table('payload_refs').'(tenant_id,id,session_id,binding_hash,content_hash,encrypted_ref,encryption_key_ref,classification,purpose,retention_until)
            VALUES(:tenant,:id,:session,:binding,:hash,:encrypted,\'example-ephemeral-key\',\'private\',:purpose,\'2030-01-01\') ON CONFLICT DO NOTHING',
            $p+['session'=>$context->session->value,'binding'=>substr($context->scopeHash($this->j->hasher)->value,8),'hash'=>substr($hash->value,8),'encrypted'=>$key->value,'purpose'=>$context->purpose]);
    }
    public function find(ExecutionContext $context,Ref $key): ?string
    {
        $context->assertCurrent($this->j->host);$context->session->tenant->assertSame($key->tenant);
        $row=$this->j->db->row('SELECT sealed,hash,scope_hash FROM '.$this->j->db->table('voice_demo_vault').' WHERE tenant_id=:tenant AND id=:id',['tenant'=>$key->tenant->value,'id'=>$key->value]);
        if ($row===null) { return null; }
        if (C::text($row,'scope_hash')!==$context->scopeHash($this->j->hasher)->value) { throw new ContractViolation(ErrorCode::AccessDenied); }
        $sealed=base64_decode(C::text($row,'sealed'),true);
        $bytes=$sealed===false?false:sodium_crypto_secretbox_open(substr($sealed,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),substr($sealed,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$this->key);
        if ($bytes===false || 'sha-256:'.hash('sha256',$bytes)!==C::text($row,'hash')) { throw new IntegrityConflict('Example vault integrity failure'); }return $bytes;
    }
    public function get(ExecutionContext $context,Ref $key): string { return $this->find($context,$key)??throw new IntegrityConflict('Missing example payload'); }
}
