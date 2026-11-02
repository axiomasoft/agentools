<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Tests\Deployments;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, IntegrityConflict, Ref, TenantId};
use AgenToolsPlatform\Core\Models\Routing\{AdminState, EmergencyRule, FallbackRouter, FallbackState, Health, Reason, Router};
use AgenToolsPlatform\Postgres\Deployments\{Authority, Repository, Schema};
use AgenToolsPlatform\Postgres\Runtime\Connection;
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;
use AgenToolsPlatform\Tests\Routing\Fixture as F;
use PHPUnit\Framework\TestCase;

final class RepositoryTest extends TestCase implements Authority
{
    private IsolatedDatabase $database;
    private string $schema;
    private Connection $db;
    private Repository $repo;
    private F $f;
    private bool $denied = false;
    protected function setUp(): void
    {
        $this->database = new IsolatedDatabase(); $this->schema = 'p3_'.bin2hex(random_bytes(12));
        $this->database->createSchema($this->schema); Schema::install($this->database->pdo,$this->schema);
        $this->db = new Connection($this->database->pdo,$this->schema); $this->f = new F();
        $this->repo = new Repository($this->db,$this->f->hasher,$this,F::ref('admin'));
    }
    protected function tearDown(): void { $this->database->dropSchema($this->schema); }
    public function authorize(TenantId $tenant, Ref $actor, string $operation): void
    { if ($this->denied) { throw new ContractViolation(ErrorCode::AccessDenied); } }
    public function testImmutableReplayAndConflict(): void
    {
        $e = $this->f->entry(); $this->repo->put($e); $this->repo->put($e);
        $view = $this->repo->view(new TenantId('alpha'));
        self::assertCount(1,$view->deployments); self::assertSame($e->hash->value,$view->deployments[0]->entry->hash->value);
        self::assertSame(AdminState::Disabled,$view->deployments[0]->admin); self::assertSame(Health::Unknown,$view->deployments[0]->health);
        $this->expectException(IntegrityConflict::class); $this->repo->put($this->f->entry(['snapshot_ref'=>'changed']));
    }
    public function testQualificationAndLiveEmergency(): void
    {
        $e = $this->f->entry(); $r = $this->f->request(); $this->repo->put($e);
        $this->repo->state($e->deployment->ref,1,AdminState::Enabled,Health::Healthy);
        $q = $this->f->qualified($e)->qualifications[0]; $this->repo->qualify($e->deployment->ref,$q); $this->repo->qualify($e->deployment->ref,$q);
        $router = new Router($this->repo,$this->f,$this->f->hasher);
        self::assertSame('ru-primary',$router->route($r,$this->f->now())->selected?->deployment->ref->value);
        $this->repo->block(new TenantId('alpha'),new EmergencyRule('incident','incident-source',account:'account-a'));
        // New repository instance reads the durable emergency, independent of pinned state.
        $fresh = new Repository($this->db,$this->f->hasher,$this,F::ref('admin'));
        self::assertSame([Reason::Emergency],(new Router($fresh,$this->f,$this->f->hasher))->revalidate($r,$e,$this->f->now(),true));
        $this->repo->lift(new TenantId('alpha'),'incident',1,F::ref('incident-reviewed'));
        self::assertSame([],$router->revalidate($r,$e,$this->f->now()));
        self::assertSame('1',(string)Connection::number($this->db->row('SELECT count(*) AS count FROM '.$this->db->table('deployment_traces')) ?? throw new \RuntimeException('Missing trace count'),'count'));
    }
    public function testStateCasAndAuthorization(): void
    {
        $e = $this->f->entry(); $this->repo->put($e);
        self::assertSame(2,$this->repo->state($e->deployment->ref,1,AdminState::Draining,Health::Healthy));
        try { $this->repo->state($e->deployment->ref,1,AdminState::Enabled,Health::Healthy); self::fail('Stale CAS accepted'); }
        catch (ContractViolation $e) { self::assertSame(ErrorCode::OptimisticConflict,$e->errorCode); }
        $this->denied = true; $this->expectException(ContractViolation::class); $this->repo->view(new TenantId('alpha'));
    }
    public function testTenantIsolationAndDeniedWrites(): void
    {
        $this->repo->put($this->f->entry());
        foreach (['read','write','emergency'] as $operation) {
            try {
                if ($operation === 'read') { $this->repo->view(new TenantId('beta')); }
                elseif ($operation === 'write') { $this->repo->put($this->f->entry(['tenant'=>'beta'])); }
                else { $this->repo->block(new TenantId('alpha'),new EmergencyRule('foreign','source',tenant:'beta')); }
                self::fail('Cross-tenant operation accepted');
            } catch (ContractViolation $error) { self::assertSame(ErrorCode::AccessDenied,$error->errorCode); }
        }
        $this->denied = true;
        try { $this->repo->state(F::ref('ru-primary'),1,AdminState::Enabled,Health::Healthy); self::fail('Denied write accepted'); }
        catch (ContractViolation $error) { self::assertSame(ErrorCode::AccessDenied,$error->errorCode); }
        $this->denied = false; self::assertSame(1,$this->repo->view(new TenantId('alpha'))->deployments[0]->version);
    }
    public function testTamperAndSchemaRevision(): void
    {
        $this->repo->put($this->f->entry()); Schema::install($this->database->pdo,$this->schema);
        $this->db->execute('UPDATE '.$this->db->table('deployment_catalog')." SET document_json='{}'");
        try { $this->repo->view(new TenantId('alpha')); self::fail('Tamper accepted'); } catch (IntegrityConflict $error) { self::assertSame('Deployment record hash mismatch',$error->getMessage()); }
        $this->db->execute('UPDATE '.$this->db->table('deployment_schema_versions')." SET source_hash='tampered'");
        $this->expectException(IntegrityConflict::class); Schema::install($this->database->pdo,$this->schema);
    }
    public function testTraceAndFallbackIdentity(): void
    {
        $a = $this->f->entry(); $b = $this->f->entry(['id'=>'ru-alternate']);
        $this->f->states = [$this->f->qualified($a),$this->f->qualified($b)];
        $route = $this->f->router()->route($this->f->request(),$this->f->now());
        $this->repo->record(F::ref('route-event'),$route); $this->repo->record(F::ref('route-event'),$route);
        $state = new FallbackState($this->f->modelRequest()->invocationId,F::ref('bundle'),F::ref('goal'),[F::ref('action')],'ru-primary',F::ref('outage'),false);
        $router = new FallbackRouter($this->f->router(),$this->f->hasher);
        $plan = $router->plan($this->f->request(),$state,$this->f->now()); $this->repo->recordFallback($plan); $this->repo->recordFallback($plan);
        self::assertFalse($plan->retryActions);
        $this->f->states = [$this->f->qualified($this->f->entry(['id'=>'different-target']))];
        $changed = $router->plan($this->f->request(),$state,$this->f->now());
        self::assertSame($plan->invocation?->value,$changed->invocation?->value);
        $this->expectException(IntegrityConflict::class); $this->repo->recordFallback($changed);
    }
}
