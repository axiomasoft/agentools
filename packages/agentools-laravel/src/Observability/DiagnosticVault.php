<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Laravel\Observability;

use AgenTools\Contracts\Clock;
use AgenToolsPlatform\Core\Common\{PayloadHash,Ref,RunId,UtcInstant};
use AgenToolsPlatform\Core\Observability\{DiagnosticAuthority,DiagnosticPermission,DiagnosticReceipt,Event,Failure,Observer,Outcome,Stage};
use AgenToolsPlatform\Core\Security\{EntryPoint,ExecutionContext,TraceLinks};
use AgenToolsPlatform\Laravel\Managed\{HostBindings,ProductionBinding};
use AgenToolsPlatform\Postgres\Runtime\{Connection,RepositoryContext,RunRepository};

/** Encrypted SQL storage separate from run inspection. No plaintext log/manifest fallback. */
final class DiagnosticVault implements ProductionBinding
{
    private bool $healthy=true;
    public function __construct(private readonly RepositoryContext $environment,private readonly DiagnosticAuthority $authority,
        private readonly KeyResolver $keys,private readonly Ref $keyRef,private readonly Clock $clock,private readonly Observer $observer,
        private readonly int $maxRetentionSeconds,private readonly int $maxBytes)
    {
        if ($maxRetentionSeconds<1 || $maxRetentionSeconds>86400 || $maxBytes<1 || $maxBytes>4194304) {
            throw new \InvalidArgumentException('Explicit bounded diagnostic policy required');
        }
    }
    public function assertProduction(): void
    {
        HostBindings::assertProductionService($this->authority);HostBindings::assertProductionService($this->keys);
    }
    public function capture(ExecutionContext $context,Ref $id,RunId $target,UtcInstant $expiresAt,#[\SensitiveParameter] string $payload): DiagnosticReceipt
    {
        try {
            $this->scope($context,$id,$target);$expires=new \DateTimeImmutable($expiresAt->value);$now=$this->clock->now();
            if ($expires<=$now || $expires>$now->modify('+'.$this->maxRetentionSeconds.' seconds') || strlen($payload)>$this->maxBytes) {
                throw new Failure('diagnostic_expired');
            }
            $content=new PayloadHash('sha-256:'.hash('sha256',$payload));
            $binding=$this->environment->hasher->hash($id->tenant,'diagnostic-binding',[
                'id'=>$id->value,'target'=>$target->value,'scope'=>$context->scopeHash($this->environment->hasher)->value,
                'expires'=>$expiresAt->value,'content_hash'=>$content->value]);
            $decision=$this->authorize($context,$target,DiagnosticPermission::Capture);
            $this->audit($context,$id,$target,$content,$decision,Outcome::Waiting);
            $this->authorize($context,$target,DiagnosticPermission::Capture);
            $key=$this->key($context);
            try {
                $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
                $plain=json_encode(['binding'=>$binding->value,'payload'=>base64_encode($payload)],JSON_THROW_ON_ERROR);
                try { $cipher=sodium_crypto_secretbox($plain,$nonce,$key); }
                finally { sodium_memzero($plain); }
            } finally { sodium_memzero($key); }
            $this->authorize($context,$target,DiagnosticPermission::Capture);
            $db=$this->environment->db;
            $db->transaction(function () use ($db,$context,$id,$target,$binding,$content,$expiresAt,$cipher,$nonce): void {
                $db->execute('INSERT INTO '.$db->table('observability_diagnostics').'
                    (tenant_id,id,session_id,target_ref,binding_hash,content_hash,key_ref,ciphertext,nonce,expires_at)
                    SELECT :tenant,:id,:session,:target,:binding,:hash,:key,:cipher,:nonce,CAST(:expiry AS timestamptz)
                    WHERE CAST(:expiry_check AS timestamptz)>clock_timestamp()
                    AND CAST(:expiry_bound AS timestamptz)<=clock_timestamp()+make_interval(secs=>:maximum)
                    ON CONFLICT DO NOTHING',
                    ['tenant'=>$id->tenant->value,'id'=>$id->value,'session'=>$context->session->value,'target'=>$target->value,
                        'binding'=>$binding->value,'hash'=>$content->value,'key'=>$this->keyRef->value,
                        'cipher'=>base64_encode($cipher),'nonce'=>base64_encode($nonce),'expiry'=>$expiresAt->value,
                        'expiry_check'=>$expiresAt->value,'expiry_bound'=>$expiresAt->value,'maximum'=>$this->maxRetentionSeconds]);
                $row=$this->load($context,$id,$target);
                if (Connection::text($row,'binding_hash')!==$binding->value || $row['purged_at']!==null) { throw new Failure('diagnostic_integrity'); }
                $this->readable($row);
            });
            $this->authorize($context,$target,DiagnosticPermission::Capture);
            $audit=$this->audit($context,$id,$target,$content,$decision,Outcome::Succeeded);
            return new DiagnosticReceipt($id,new Ref($target->tenant,$target->value),$content,$expiresAt,$audit);
        } catch (Failure $e) {
            if ($e->reason!=='audit_unavailable' && $context->session->tenant->value===$id->tenant->value && $target->tenant->value===$id->tenant->value) {
                $this->audit($context,$id,$target,$this->environment->hasher->hash($id->tenant,'diagnostic-denied',$id->value),null,Outcome::Denied);
            }
            throw $e;
        }
        catch (\Throwable) { throw new Failure('diagnostic_unavailable'); }
        finally { $this->reset(); }
    }
    public function read(ExecutionContext $context,Ref $id,RunId $target): SensitivePayload
    {
        $plain=null; $payload=null;
        try {
            $this->scope($context,$id,$target);
            $decision=$this->authorize($context,$target,DiagnosticPermission::Read);
            $row=$this->load($context,$id,$target);$this->readable($row);
            $hash=new PayloadHash(Connection::text($row,'content_hash'));
            $this->audit($context,$id,$target,$hash,$decision,Outcome::Waiting);
            $this->authorize($context,$target,DiagnosticPermission::Read);
            if (Connection::text($row,'key_ref')!==$this->keyRef->value) { throw new Failure('diagnostic_integrity'); }
            $key=$this->key($context);
            try {
                $cipher=base64_decode(Connection::text($row,'ciphertext'),true);$nonce=base64_decode(Connection::text($row,'nonce'),true);
                if ($cipher===false || $nonce===false || strlen($nonce)!==SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) { throw new Failure('diagnostic_integrity'); }
                $plain=sodium_crypto_secretbox_open($cipher,$nonce,$key);
                if ($plain===false) { throw new Failure('diagnostic_integrity'); }
            } finally { sodium_memzero($key); }
            $document=json_decode($plain,true,16,JSON_THROW_ON_ERROR);
            if (!is_array($document) || ($document['binding']??null)!==Connection::text($row,'binding_hash') || !is_string($document['payload']??null)) {
                throw new Failure('diagnostic_integrity');
            }
            $payload=base64_decode($document['payload'],true);unset($document);
            if ($payload===false || strlen($payload)>$this->maxBytes || 'sha-256:'.hash('sha256',$payload)!==$hash->value) { throw new Failure('diagnostic_integrity'); }
            $this->authorize($context,$target,DiagnosticPermission::Read);$this->readable($this->load($context,$id,$target));
            $this->audit($context,$id,$target,$hash,$decision,Outcome::Succeeded);
            $this->authorize($context,$target,DiagnosticPermission::Read);$this->readable($this->load($context,$id,$target));
            return new SensitivePayload($payload);
        } catch (Failure $e) {
            if ($e->reason!=='audit_unavailable' && $context->session->tenant->value===$id->tenant->value && $target->tenant->value===$id->tenant->value) {
                $this->audit($context,$id,$target,$this->environment->hasher->hash($id->tenant,'diagnostic-denied',$id->value),null,Outcome::Denied);
            }
            throw $e;
        }
        catch (\Throwable) { throw new Failure('diagnostic_unavailable'); }
        finally {
            if (is_string($plain)) { sodium_memzero($plain); }
            if (is_string($payload)) { sodium_memzero($payload); }
            $this->reset();
        }
    }
    public function prune(ExecutionContext $context,Ref $id,RunId $target): void
    {
        try {
            $this->scope($context,$id,$target);$decision=$this->authorize($context,$target,DiagnosticPermission::Purge);
            $row=$this->load($context,$id,$target);$hash=new PayloadHash(Connection::text($row,'content_hash'));
            $this->audit($context,$id,$target,$hash,$decision,Outcome::Waiting);
            $this->authorize($context,$target,DiagnosticPermission::Purge);
            $db=$this->environment->db;
            $changed=$db->transaction(fn(): int=>$db->execute('UPDATE '.$db->table('observability_diagnostics').'
                SET ciphertext=NULL,nonce=NULL,purged_at=clock_timestamp() WHERE tenant_id=:tenant AND id=:id
                AND session_id=:session AND target_ref=:target AND expires_at<=clock_timestamp() AND hold_ref IS NULL AND purged_at IS NULL',
                ['tenant'=>$id->tenant->value,'id'=>$id->value,'session'=>$context->session->value,'target'=>$target->value])->rowCount());
            if ($changed===0 && $row['purged_at']===null) { throw new Failure('diagnostic_denied'); }
            $this->audit($context,$id,$target,$hash,$decision,Outcome::Succeeded);
        } catch (Failure $e) {
            if ($e->reason!=='audit_unavailable' && $context->session->tenant->value===$id->tenant->value && $target->tenant->value===$id->tenant->value) {
                $this->audit($context,$id,$target,$this->environment->hasher->hash($id->tenant,'diagnostic-denied',$id->value),null,Outcome::Denied);
            }
            throw $e;
        }
        catch (\Throwable) { throw new Failure('diagnostic_unavailable'); }
        finally { $this->reset(); }
    }
    private function scope(ExecutionContext $context,Ref $id,RunId $target): void
    {
        if (!$this->healthy) { throw new Failure('reset_failed'); }
        $context->session->tenant->assertSame($id->tenant);$id->tenant->assertSame($target->tenant);$id->tenant->assertSame($this->keyRef->tenant);
        if ((new RunRepository($this->environment))->get($context,$target)===null) { throw new Failure('diagnostic_denied'); }
    }
    private function authorize(ExecutionContext $context,RunId $target,DiagnosticPermission $permission): Ref
    {
        try {
            $this->environment->authorize($context);
            $ref=$this->authority->authorize($context,new Ref($target->tenant,$target->value),$permission);
            $target->tenant->assertSame($ref->tenant);return $ref;
        } catch (\Throwable) { throw new Failure('diagnostic_denied'); }
    }
    private function key(ExecutionContext $context): string
    {
        try { $key=$this->keys->resolve($context,$this->keyRef); }
        catch (\Throwable) { throw new Failure('resolver_unavailable'); }
        if (strlen($key)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES) { sodium_memzero($key);throw new Failure('resolver_unavailable'); }
        return $key;
    }
    private function audit(ExecutionContext $context,Ref $id,RunId $target,PayloadHash $hash,?Ref $decision,Outcome $outcome): Ref
    {
        return $this->observer->emit(new Event(new Ref($id->tenant,'diagnostic-audit:'.bin2hex(random_bytes(16))),$context,Stage::Diagnostics,
            $outcome,EntryPoint::Background,new TraceLinks(run:new Ref($target->tenant,$target->value),diagnostic:$id,decision:$decision,hash:$hash),$this->environment->hasher));
    }
    /** @return array<string,mixed> */
    private function load(ExecutionContext $context,Ref $id,RunId $target): array
    {
        $db=$this->environment->db;
        return $db->row('SELECT *,expires_at>clock_timestamp() AS live FROM '.$db->table('observability_diagnostics').'
            WHERE tenant_id=:tenant AND id=:id AND session_id=:session AND target_ref=:target',
            ['tenant'=>$id->tenant->value,'id'=>$id->value,'session'=>$context->session->value,'target'=>$target->value])??throw new Failure('diagnostic_unavailable');
    }
    /** @param array<string,mixed> $row */
    private function readable(array $row): void
    {
        if ($row['purged_at']!==null || ($row['live']??false)!==true || $this->clock->now()>=new \DateTimeImmutable(Connection::text($row,'expires_at'))) {
            throw new Failure('diagnostic_expired');
        }
    }
    public function reset(): void
    {
        $failed=false;
        foreach ([$this->keys->reset(...),$this->authority->reset(...)] as $reset) {
            try { $reset(); } catch (\Throwable) { $failed=true; }
        }
        if ($failed) { $this->healthy=false;throw new Failure('reset_failed'); }
    }
    /** @return array{encrypted:bool} */
    public function __debugInfo(): array { return ['encrypted'=>true]; }
}
