<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Core\Models\Cache;

use AgenToolsPlatform\Core\Common\{CanonicalHasher,PayloadHash,Ref,TenantId,UtcInstant};

/** Closed versioned stable namespace. No run/session/random prefix is accepted. */
final readonly class Key
{
    /** @param array<string,mixed> $dimensions */
    private function __construct(public Kind $kind,public Ref $id,public array $dimensions) {}
    /** @param array<string,mixed> $d */
    public static function fromArray(array $d,CanonicalHasher $hasher): self
    {
        $fields=['key_version','kind','provider','deployment_ref','model_revision','api_mode','credential_ref','project_scope','region',
            'tenant_ref','security_domain','security_version','personal','subject_ref','retention_scope','retention_until',
            'profile_hash','skills_hash','tools_hash','materials_hash','release_hash','renderer_version','serialization_version'];
        if (array_diff(array_keys($d),$fields)!==[] || array_diff($fields,array_keys($d))!==[] || $d['key_version']!=='1') {
            throw new \InvalidArgumentException('Closed versioned cache key required');
        }
        foreach (array_diff($fields,['personal','subject_ref','retention_scope','retention_until']) as $field) { self::text($d[$field]); }
        $kind=Kind::from(self::text($d['kind'])); $tenant=new \AgenToolsPlatform\Core\Common\TenantId(self::text($d['tenant_ref']));
        foreach (['deployment_ref','credential_ref'] as $field) { new \AgenToolsPlatform\Core\Common\Ref($tenant,self::text($d[$field])); }
        foreach (['profile_hash','skills_hash','tools_hash','materials_hash','release_hash'] as $field) { new \AgenToolsPlatform\Core\Common\PayloadHash(self::text($d[$field])); }
        if (!is_bool($d['personal'])) { throw new \InvalidArgumentException('Explicit personal classification required'); }
        if ($d['personal']) {
            new \AgenToolsPlatform\Core\Common\Ref($tenant,self::text($d['subject_ref'])); self::text($d['retention_scope']);
            $d['retention_until']=(new \AgenToolsPlatform\Core\Common\UtcInstant(self::text($d['retention_until'])))->value;
        } elseif ($d['subject_ref']!==null || $d['retention_scope']!==null || $d['retention_until']!==null) {
            throw new \InvalidArgumentException('Nonpersonal shared namespace cannot carry a subject');
        }
        return new \AgenToolsPlatform\Core\Models\Cache\Key($kind,new \AgenToolsPlatform\Core\Common\Ref($tenant,$hasher->hash($tenant,'cache-key.v1',$d)->value),$d);
    }
    public function assertSame(self $other): void { $this->id->assertSame($other->id); }
    /** Rebuild may refresh permitted source versions, never silently choose a cheaper route/security scope. */
    public function assertSameRoute(self $other): void
    {
        foreach (['kind','provider','deployment_ref','model_revision','api_mode','credential_ref','project_scope','region','tenant_ref',
            'security_domain','security_version','personal','subject_ref','retention_scope','retention_until'] as $field) {
            if ($this->dimensions[$field]!==$other->dimensions[$field]) { throw new \InvalidArgumentException('Cache rebuild changed authorized route or scope'); }
        }
    }
    public function assertRetained(UtcInstant $now,UtcInstant $expiry): void
    {
        if (\AgenToolsPlatform\Core\Models\Time::compare($now,$expiry)>=0) { throw new \InvalidArgumentException('Cache expired'); }
        if ($this->dimensions['personal']) {
            $limit=new \AgenToolsPlatform\Core\Common\UtcInstant(self::text($this->dimensions['retention_until']));
            if (\AgenToolsPlatform\Core\Models\Time::compare($expiry,$limit)>0) { throw new \InvalidArgumentException('Cache exceeds personal retention'); }
        }
    }
    public function assertCurrent(UtcInstant $now): void
    {
        if ($this->dimensions['personal'] && \AgenToolsPlatform\Core\Models\Time::compare($now,new \AgenToolsPlatform\Core\Common\UtcInstant(self::text($this->dimensions['retention_until'])))>=0) {
            throw new \LogicException('Personal cache retention expired');
        }
    }
    private static function text(mixed $value): string
    { if (!is_string($value) || trim($value)==='') { throw new \InvalidArgumentException('Complete cache dimensions required'); } return $value; }
}
