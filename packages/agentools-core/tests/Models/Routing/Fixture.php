<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Tests\Routing;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, InvocationId, PayloadHash, Ref, TenantId, UtcInstant};
use AgenToolsPlatform\Core\Models\{Capability, ContentPart, Deployment, FinishReason, MessageRole, ModelMessage, ModelRequest, ModelResponse, ModelTransport, Support};
use AgenToolsPlatform\Core\Models\Routing\{AdminState, Catalog, CatalogEntry, CatalogView, Clock, DeploymentState, EmergencyRule, Health, HostDecision, HostPolicy, Qualification, QualificationTuple, RouteRequest, Router};
use AgenToolsPlatform\Tests\JcsAdapter;

final class Fixture implements Catalog, HostPolicy, Clock, ModelTransport
{
    public CanonicalHasher $hasher;
    /** @var list<DeploymentState> */
    public array $states = [];
    /** @var list<EmergencyRule> */
    public array $rules = [];
    public HostDecision $host;
    public int $calls = 0;
    public int $policyCalls = 0;
    public bool $outage = false;
    public int $ownerLookups = 0;
    /** @var array<string,array{action:string,outcome:string}> */
    private array $ownerOperations = ['owner-key-1'=>['action'=>'action-1','outcome'=>'success']];
    public function __construct()
    { $this->hasher = new CanonicalHasher(new JcsAdapter()); $this->host = new HostDecision(Support::Supported,Support::Supported,Support::Supported); }
    public function now(): UtcInstant { return new UtcInstant('2026-11-02T10:00:00Z'); }
    public static function ref(string $id, string $tenant = 'alpha'): Ref { return new Ref(new TenantId($tenant),$id); }
    public static function digest(string $value = 'a'): PayloadHash { return new PayloadHash('sha-256:'.str_repeat($value,64)); }
    /** @param array<string,mixed> $changes */
    public function entry(array $changes = []): CatalogEntry
    {
        $data = self::read('catalog.json');
        return CatalogEntry::fromArray(array_replace($data,$changes),$this->hasher);
    }
    /** @param array{subject?:Ref,host?:Ref,bundle?:Ref,task?:string,purpose?:string,regions?:list<string>,dataClasses?:list<string>,capabilities?:list<Capability>,suiteVersions?:array<string,string>,promptHash?:PayloadHash,skillsHash?:PayloadHash,toolsHash?:PayloadHash,corpusHash?:PayloadHash,knownRoute?:string} $changes */
    public function request(array $changes = []): RouteRequest
    {
        return new RouteRequest($changes['subject'] ?? self::ref('actor'),$changes['host'] ?? self::ref('host'),$changes['bundle'] ?? self::ref('bundle'),
            $changes['task'] ?? 'teacher-text',$changes['purpose'] ?? 'lesson',$changes['regions'] ?? ['RU'],$changes['dataClasses'] ?? ['private'],
            $changes['capabilities'] ?? [Capability::Text],$changes['suiteVersions'] ?? ['technical/v1'=>self::digest()->value],
            $changes['promptHash'] ?? self::digest(),$changes['skillsHash'] ?? self::digest(),$changes['toolsHash'] ?? self::digest(),
            $changes['corpusHash'] ?? self::digest(),$changes['knownRoute'] ?? null);
    }
    /** @param array{entry?:CatalogEntry,admin?:AdminState,health?:Health,version?:int,qualifications?:list<Qualification>} $changes */
    public function qualified(CatalogEntry $entry, ?RouteRequest $request = null, array $changes = []): DeploymentState
    {
        $request ??= $this->request();
        $q = new Qualification(self::ref('q-'.$entry->deployment->ref->value),QualificationTuple::hash($request,$entry,$this->hasher),
            'qualified',new UtcInstant('2026-11-01T00:00:00Z'),new UtcInstant('2026-12-01T00:00:00Z'),self::ref('technical-results'));
        return new DeploymentState($changes['entry'] ?? $entry,$changes['admin'] ?? AdminState::Enabled,$changes['health'] ?? Health::Healthy,
            $changes['version'] ?? 1,$changes['qualifications'] ?? [$q]);
    }
    public function view(TenantId $tenant): CatalogView { return new CatalogView($this->states,$this->rules); }
    public function check(RouteRequest $request, CatalogEntry $entry, UtcInstant $now): HostDecision { ++$this->policyCalls; return $this->host; }
    /** @param array<string,string> $knownRoutes */
    public function router(array $knownRoutes = []): Router { return new Router($this,$this,$this->hasher,$knownRoutes); }
    public function modelRequest(): ModelRequest
    { return new ModelRequest(new InvocationId(new TenantId('alpha'),'invocation-1'),[new ModelMessage(MessageRole::User,[ContentPart::text('hello')])],self::ref('snapshot'),self::digest(),self::ref('credential-1'),new UtcInstant('2026-11-03T00:00:00Z')); }
    public function generate(ModelRequest $request, Deployment $deployment): ModelResponse
    { ++$this->calls; if ($this->outage) { throw new \RuntimeException('injected provider outage'); } return new ModelResponse($request->invocationId,$deployment,$deployment,'scripted-request',FinishReason::Completed,[ContentPart::text('checked text')]); }
    public function stream(ModelRequest $request, Deployment $deployment): iterable { ++$this->calls; yield from []; }
    /** @return array<string,mixed> */
    public static function object(mixed $data): array
    {
        if (!is_array($data)) { throw new \RuntimeException('Fixture object required'); }
        foreach ($data as $key=>$_) { if (!is_string($key)) { throw new \RuntimeException('Fixture object keys required'); } }
        return $data;
    }
    /** @return array<string,mixed> */
    public static function read(string $name): array
    {
        $json = file_get_contents(dirname(__DIR__,5).'/fixtures/platform/v1/routing/'.$name);
        if ($json === false) { throw new \RuntimeException('Missing routing fixture'); }
        return self::object(json_decode($json,true,flags:JSON_THROW_ON_ERROR));
    }
    /** @return list<array{id:string,disclosed:bool,unknown_effect:bool,forbidden_only:bool,explicit:bool,allowed:bool,outcome:string}> */
    public static function fallbackMatrix(): array
    {
        $raw = self::read('fallback-matrix.json')['cases']; $result = [];
        if (!is_array($raw) || !array_is_list($raw) || $raw === []) { throw new \RuntimeException('Missing fallback matrix'); }
        foreach ($raw as $value) {
            $c = self::object($value);
            $result[] = ['id'=>self::text($c,'id'),'disclosed'=>self::boolean($c,'disclosed'),
                'unknown_effect'=>self::boolean($c,'unknown_effect'),'forbidden_only'=>self::boolean($c,'forbidden_only'),
                'explicit'=>self::boolean($c,'explicit'),'allowed'=>self::boolean($c,'allowed'),'outcome'=>self::text($c,'outcome')];
        }
        return $result;
    }
    /** @param array<string,mixed> $data */
    private static function text(array $data, string $key): string
    { $v = $data[$key] ?? null; if (!is_string($v)) { throw new \RuntimeException('Missing fixture string'); } return $v; }
    /** @param array<string,mixed> $data */
    private static function boolean(array $data, string $key): bool
    { $v = $data[$key] ?? null; if (!is_bool($v)) { throw new \RuntimeException('Missing fixture boolean'); } return $v; }
    public static function rule(string $field, string|bool $value): EmergencyRule
    {
        if ($field === 'audio') {
            if (!is_bool($value)) { throw new \InvalidArgumentException('Boolean audio selector required'); }
            return new EmergencyRule('incident','source',audio:$value);
        }
        if (!is_string($value)) { throw new \InvalidArgumentException('String emergency selector required'); }
        return match ($field) {
            'provider'=>new EmergencyRule('incident','source',provider:$value), 'account'=>new EmergencyRule('incident','source',account:$value),
            'deployment'=>new EmergencyRule('incident','source',deployment:$value), 'model'=>new EmergencyRule('incident','source',model:$value),
            'region'=>new EmergencyRule('incident','source',region:$value), 'tenant'=>new EmergencyRule('incident','source',tenant:$value),
            'task'=>new EmergencyRule('incident','source',task:$value), default=>throw new \InvalidArgumentException('Unknown selector'),
        };
    }
    /** @return array{action:string,outcome:string} */
    public function lookupOwner(Ref $operation): array
    { ++$this->ownerLookups; return $this->ownerOperations[$operation->value] ?? throw new \RuntimeException('Unknown owner key'); }
    public function ownerOperationCount(): int { return count($this->ownerOperations); }
}
