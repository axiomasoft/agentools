<?php
declare(strict_types=1);
namespace AgenToolsPlatform\ModelAdapter\Tests\Cache;
use AgenToolsPlatform\Core\Budget\{AdmissionScope,BudgetBoundary};
use AgenToolsPlatform\Core\Common\{CanonicalHasher,PayloadHash,Ref,TenantId,UtcInstant};
use AgenToolsPlatform\Core\Models\Cache\{Descriptor,Key,Prepared,Store,WarmPolicy};
use AgenToolsPlatform\Core\Usage\ProviderMapping;
use AgenToolsPlatform\ModelAdapter\Cache\{Manager,WarmAccounting,WarmRequest};
use AgenToolsPlatform\Tests\JcsAdapter;

final class Fixture
{
    public CanonicalHasher $hasher;
    public UtcInstant $now;
    public Store $store;
    public Host $host;
    public Provider $provider;
    public Sink $sink;
    public BudgetOwner $budget;
    public WarmAccounting $accounting;
    public Manager $manager;
    public function __construct(?Store $store=null)
    {
        $this->hasher=new CanonicalHasher(new JcsAdapter()); $this->now=new UtcInstant(gmdate('Y-m-d\TH:i:s\Z'));
        $this->store=$store ?? new MemoryStore($this); $this->host=new Host($this); $this->provider=new Provider($this); $this->sink=new Sink($this);
        $this->budget=new BudgetOwner($this);
        $mapping=new ProviderMapping('fixture-cache-total-v1',['input'=>'inputTokens','output'=>'outputTokens','cache_read'=>'cacheReadTokens','cache_write'=>'cacheWriteTokens','provider_amount'=>'amount'],true,'separate','subset_of_output');
        $this->accounting=new WarmAccounting(new BudgetBoundary($this->budget,$this->budget,$this->hasher),$this->sink,$this->hasher,$mapping);
        $this->manager=new Manager($this->store,$this->host,$this->provider,$this->host,$this->host,$this->accounting,fn (): UtcInstant=>$this->now);
    }
    /** @return array<string,mixed> */
    public static function document(string $name): array
    { return \AgenToolsPlatform\Core\Usage\Values::object(json_decode(file_get_contents(dirname(__DIR__,4).'/fixtures/platform/v1/cache/'.$name.'.json'),true,flags:JSON_THROW_ON_ERROR)); }
    /** @param array<string,mixed> $changes */
    public function key(array $changes=[]): Key { return Key::fromArray(array_replace(self::document('key'),$changes),$this->hasher); }
    public function ref(string $id): Ref { return new Ref(new TenantId('alpha'),$id); }
    public function descriptor(?Key $key=null,?UtcInstant $expiry=null): Descriptor
    { return new Descriptor($key ?? $this->key(),$this->ref('provider-resource'),new UtcInstant('2020-01-01T00:00:00Z'),$expiry ?? new UtcInstant('2030-01-01T00:00:00Z'),$this->now,$this->ref('current-rights')); }
    public function prepared(Key $key): Prepared
    { return new Prepared($key,new Ref($key->id->tenant,'snapshot-current'),new PayloadHash('sha-256:'.str_repeat('f',64)),100,1000,100,100,10); }
    public function policy(int $ops=2,int $units=100): WarmPolicy
    { return new WarmPolicy(new PayloadHash(self::document('key')['profile_hash']),new UtcInstant('2020-01-01T00:00:00Z'),new UtcInstant('2030-01-01T00:00:00Z'),$ops,$units,2,60,
        new \AgenToolsPlatform\Core\Models\Cache\WarmEconomics(10,100,5,1,1000,500,'USD','synthetic','USD','synthetic')); }
    public function warm(string $operation='warm-1',string $kind='create'): WarmRequest
    {
        $d=\AgenToolsPlatform\Tests\Usage\Fixture::read('admission-request'); $key=$this->key();
        $d['operation_key']='admit:'.$operation; $d['invocation_ref']='invoke:'.$operation; $d['business_operation_ref']='warm-profile:'.$key->dimensions['profile_hash'];
        $d['deployment_ref']=$key->dimensions['deployment_ref']; $d['profile_hash']=$key->dimensions['profile_hash']; $d['desired_deadline']='2030-01-01T00:00:00Z';
        $meta=\AgenToolsPlatform\Tests\Usage\Fixture::read('metadata'); $meta['invocation_ref']=$d['invocation_ref'];
        $meta['business_operation_ref']=$d['business_operation_ref']; $meta['deployment_ref']=$d['deployment_ref']; $meta['subject_ref']=null;
        return new WarmRequest($this->ref($operation),AdmissionScope::fromArray($d),$meta,new UtcInstant('2030-01-01T00:00:00Z'),10,5,$kind);
    }
}
