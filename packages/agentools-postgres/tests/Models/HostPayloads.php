<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Tests\Models;

use AgenToolsPlatform\Core\Common\{IntegrityConflict, PayloadHash, Ref};
use AgenToolsPlatform\Core\Runtime\InvocationPayloads;
use AgenToolsPlatform\Core\Security\ExecutionContext;

/** Test-only vault, deliberately isolated from product autoload. Production must supply encryption/policy. */
final class HostPayloads implements InvocationPayloads
{
    public function __construct(private TestRig $rig) {}
    public function put(ExecutionContext $context, Ref $key, PayloadHash $hash, string $json): void
    {
        $this->rig->boundary('vault');
        $context->session->tenant->assertSame($key->tenant);
        $hash->assertSame(new PayloadHash('sha-256:' . hash('sha256', $json)));
        $this->rig->sql('INSERT INTO host_payloads(tenant,id,body,hash) VALUES (:tenant,:id,:body,:hash) ON CONFLICT DO NOTHING', [
            'tenant' => $key->tenant->value, 'id' => $key->value, 'body' => $json, 'hash' => $hash->value]);
        if ($this->find($context, $key) !== $json) { throw new IntegrityConflict('Immutable payload changed'); }
        $this->rig->sql("INSERT INTO payload_refs(tenant_id,id,session_id,binding_hash,content_hash,encrypted_ref,encryption_key_ref,classification,purpose,retention_until)
            VALUES (:tenant,:id,:session,:binding,:hash,'test-vault','test-key','private','assist.read','2031-01-01') ON CONFLICT DO NOTHING", [
                'tenant' => $key->tenant->value, 'id' => $key->value, 'session' => $context->session->value,
                'binding' => str_repeat('a', 64), 'hash' => substr($hash->value, 8)]);
        $this->rig->boundary(str_ends_with($key->value, ':accepted') ? 'payload' : 'unknown-payload');
    }
    public function find(ExecutionContext $context, Ref $key): ?string
    {
        $this->rig->boundary('vault-read');
        $context->assertCurrent($this->rig->fixture->host); $context->session->tenant->assertSame($key->tenant);
        $body = $this->rig->sql('SELECT body FROM host_payloads WHERE tenant=:tenant AND id=:id',
            ['tenant' => $key->tenant->value, 'id' => $key->value])->fetchColumn();
        return is_string($body) ? $body : null;
    }
    public function get(ExecutionContext $context, Ref $key): string { return $this->find($context, $key) ?? throw new \RuntimeException('Payload missing'); }
}
