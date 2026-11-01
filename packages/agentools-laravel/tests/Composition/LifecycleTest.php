<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Tests\Composition;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode};
use AgenToolsPlatform\Core\Models\ModelTransport;
use AgenToolsPlatform\Laravel\Jobs\RunInput;
use AgenToolsPlatform\Laravel\Managed\{Entry, Executor, HostBindings, Input, Scope};
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\{JobExceptionOccurred, JobProcessed, JobProcessing};
use PHPUnit\Framework\TestCase;

final class LifecycleTest extends TestCase
{
    private Fixture $f;
    protected function setUp(): void { $this->f = new Fixture(); }
    protected function tearDown(): void { $this->f->close(); }

    public function testTenantAThenBOnRecycledWorker(): void
    {
        $f = $this->f;
        $job = $this->createMock(Job::class);
        $f->events->dispatch(new JobProcessing('test',$job));
        $alphaPort = $f->app->make(ModelTransport::class);
        $alphaExecutor = $f->app->make(Executor::class);
        (new RunInput('host:alpha'))->handle($f->app->make(Executor::class));
        $alpha = $f->last;
        self::assertSame([],$f->trace->state);
        $f->events->dispatch(new JobProcessed('test',$job));
        try { $alphaExecutor->execute(new Input('host:stale')); self::fail('Retired facade reused'); }
        catch (ContractViolation $error) { self::assertSame(ErrorCode::PolicyConflict,$error->errorCode); }
        $f->app->forgetScopedInstances();
        $f->tenant = 'beta';
        $f->trace->events = [];
        $f->events->dispatch(new JobProcessing('test',$job));
        $betaPort = $f->app->make(ModelTransport::class);
        self::assertNotSame($alphaPort,$betaPort);
        $receipt = $f->app->make(Executor::class)->execute(new Input('host:beta',Entry::Background));
        self::assertSame('beta',$receipt->id->tenant->value);
        self::assertNotSame($alpha,$f->last);
        self::assertSame([],$f->trace->state);
        self::assertStringNotContainsString('alpha',json_encode($receipt,JSON_THROW_ON_ERROR));
        self::assertSame(1,$f->current()->count('confirmation_previews'));
        self::assertStringNotContainsString('alpha',json_encode(array_slice($f->trace->snapshots,-1),JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('alpha',json_encode(array_slice($f->trace->requests,-1),JSON_THROW_ON_ERROR));
        self::assertGreaterThanOrEqual(4,$f->encrypted->resets);
    }

    public function testExceptionCancelAndOctaneResetAllState(): void
    {
        $f = $this->f;
        $f->trace->deny = 'profile/data';
        try { $f->app->make(Executor::class)->execute(new Input('host:error')); self::fail('Denied job accepted'); }
        catch (ContractViolation) {}
        self::assertSame([],$f->trace->state);
        $f->events->dispatch(new JobExceptionOccurred('test',$this->createMock(Job::class),new \RuntimeException('host error')));
        $f->trace->deny = null;
        $f->trace->cancelAtDeployment = true;
        $f->trace->cancel = fn () => $f->events->dispatch(new \Laravel\Octane\Events\TickTerminated($f->app,$f->app));
        $f->instance = 'cancel';
        try { $f->app->make(Executor::class)->execute(new Input('host:cancel')); self::fail('Cancelled job executed'); }
        catch (ContractViolation $error) { self::assertSame(ErrorCode::Cancelled,$error->errorCode); }
        self::assertNotContains('model',$f->trace->events);
        self::assertSame([],$f->trace->state);
        $oldScope = $f->app->make(Scope::class);
        try { $oldScope->assertActive(); self::fail('Retained scope usable'); } catch (ContractViolation) {}
        $f->trace->cancelAtDeployment = false;
        $f->tenant = 'beta'; $f->instance = 'after-cancel'; $f->trace->events = [];
        $receipt = $f->app->make(Executor::class)->execute(new Input('host:next'));
        self::assertSame('beta',$receipt->id->tenant->value);
        self::assertSame([],$f->trace->state);
    }

    public function testResetFailureIsRedactedAndPoisonsScope(): void
    {
        $f = $this->f; $f->trace->failReset = true;
        try { $f->app->make(Executor::class)->execute(new Input('host:failure')); self::fail('Reset failure ignored'); }
        catch (\RuntimeException $error) {
            self::assertSame('Managed lifecycle reset failed',$error->getMessage());
            self::assertNull($error->getPrevious());
        }
        self::assertSame([],$f->trace->state);
        self::assertGreaterThanOrEqual(2,$f->encrypted->resets);
        $f->trace->failReset = false;
        $sandbox = clone $f->app;
        $sandbox->forgetScopedInstances();
        try { $sandbox->make(Executor::class)->execute(new Input('host:other-sandbox')); self::fail('Unhealthy worker reused'); }
        catch (\AgenToolsPlatform\Laravel\Managed\LifecycleFailure) {}
        $f->app->forgetScopedInstances();
        $this->expectException(\AgenToolsPlatform\Laravel\Managed\LifecycleFailure::class);
        $f->app->make(Executor::class)->execute(new Input('host:poisoned'));
    }

    public function testOctaneCleansEventSandboxAndUnusedPorts(): void
    {
        $f = $this->f;
        $sandbox = clone $f->app;
        $sandbox->forgetScopedInstances();
        $old = $sandbox->make(ModelTransport::class);
        $f->trace->state = ['credential'=>'alpha:secret','cache'=>'alpha:source'];
        self::assertTrue($sandbox->make(Scope::class)->ownsBindings());
        $f->events->dispatch(new \Laravel\Octane\Events\TickTerminated($f->app,$sandbox));
        self::assertSame([],$f->trace->state);
        self::assertFalse($sandbox->make(Scope::class)->ownsBindings());
        self::assertSame(1,$f->factories);
        $f->events->dispatch(new \Laravel\Octane\Events\TickTerminated($f->app,$sandbox));
        self::assertSame(1,$f->factories,'Cleanup instantiated an unused user scope');
        self::assertNotSame($old,$sandbox->make(ModelTransport::class));
        self::assertSame(2,$f->factories);
        $f->events->dispatch(new \Laravel\Octane\Events\TickTerminated($f->app,$sandbox));
    }

    public function testCleanupRollsBackAndDiscardsDatabaseSession(): void
    {
        $f = $this->f;
        $ports = $f->app->make(HostBindings::class);
        $pdo = $f->current()->database->pdo;
        $pdo->exec("SET agentools.tenant = 'alpha'");
        $pdo->beginTransaction();
        $ports->connection->execute('INSERT INTO '.$ports->connection->table('write_messages')." VALUES ('alpha','uncommitted','hash','receipt')");
        $f->events->dispatch(new \Laravel\Octane\Events\TickTerminated($f->app,$f->app));
        self::assertFalse($pdo->inTransaction());
        $settings = $pdo->query("SELECT current_setting('agentools.tenant',true)");
        self::assertNotFalse($settings);
        self::assertContains($settings->fetchColumn(),[null,'']);
        self::assertSame(0,$f->current()->count('write_messages'));
        self::assertFalse($f->app->make(Scope::class)->ownsBindings());
    }

    public function testAccountingFailureIsNotOptionalTelemetry(): void
    {
        $f = $this->f;
        $f->app->instance('agentools.options',['ignore_observability_failure'=>true]);
        $f->trace->rawFailure = 'budget';
        try { $f->app->make(Executor::class)->execute(new Input('host:accounting')); self::fail('Accounting failure ignored'); }
        catch (ContractViolation $error) {
            self::assertSame(ErrorCode::PolicyConflict,$error->errorCode);
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('alpha secret',$error->getMessage());
        }
        self::assertNotContains('model',$f->trace->events);
        self::assertNotContains('delivery',$f->trace->events);
        self::assertSame([],$f->trace->state);
    }

    public function testCommandUsesManagedAdmission(): void
    {
        $f = $this->f;
        $artisan = new \Illuminate\Console\Application($f->app,$f->events,'test');
        $f->app->instance('artisan',$artisan);
        self::assertSame(0,$artisan->call('agentools:run',['input_ref'=>'host:command']));
        self::assertContains('auth',$f->trace->events);
        self::assertContains('disclosure',$f->trace->events);
        self::assertSame([],$f->trace->state);
        self::assertStringContainsString('acknowledgement',$artisan->output());
        self::assertStringNotContainsString('alpha:credential',$artisan->output());
    }

    public function testCleanupAttemptsAllPortsAfterStoreFailure(): void
    {
        $f = $this->f; $f->encrypted->fail = true;
        $scope = $f->app->make(Scope::class);
        $f->trace->resetProbe = static function () use ($scope,$f): void {
            try { $scope->assertAvailable(); } catch (\LogicException) { ++$f->trace->resetDenied; }
        };
        try { $f->app->make(Executor::class)->execute(new Input('host:store-failure')); self::fail('Store failure ignored'); }
        catch (\RuntimeException $error) { self::assertStringNotContainsString('alpha',$error->getMessage()); }
        self::assertSame([],$f->trace->state);
        self::assertGreaterThanOrEqual(2,count(array_filter($f->trace->events,fn ($event) => $event === 'reset')));
        self::assertSame(2,$f->trace->resetDenied,'Nested entry became available during cleanup');
    }

    public function testProviderIsOptInScopedAndHasNoRoutes(): void
    {
        $f = $this->f;
        self::assertCount(0,$f->app->make(\Illuminate\Routing\Router::class)->getRoutes()->getRoutes());
        self::assertSame($f->app->make(Executor::class),$f->app->make(Executor::class));
        $first = $f->app->make(Scope::class);
        $f->app->forgetScopedInstances();
        self::assertNotSame($first,$f->app->make(Scope::class));
        $manifest = json_decode((string)file_get_contents(dirname(__DIR__,2).'/composer.json'),true,512,JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertIsArray($manifest['extra']);
        self::assertArrayNotHasKey('laravel',$manifest['extra']);
        $serialized = serialize(new RunInput('host:job'));
        self::assertStringNotContainsString('tenant',$serialized);
        self::assertStringNotContainsString('credential',$serialized);
        $decoded = unserialize($serialized);
        self::assertInstanceOf(RunInput::class,$decoded);
        self::assertSame('host:job',$decoded->inputRef);
        self::assertSame('agentools:run',(new \AgenToolsPlatform\Laravel\Console\RunInput())->getName());
    }
}
