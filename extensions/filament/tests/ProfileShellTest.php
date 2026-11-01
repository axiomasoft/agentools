<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Filament\Tests;

use AgenToolsPlatform\Filament\{InspectorPlugin, InspectorServiceProvider, Pages\Inspect, Pages\Profiles, ProfilePlugin};
use AgenToolsPlatform\Filament\Profiles\{Admin, Result};
use Filament\Panel;
use Illuminate\Config\Repository;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Routing\{Router, RoutingServiceProvider};
use Illuminate\Support\Facades\Facade;
use Illuminate\View\{Factory, ViewServiceProvider};
use Illuminate\Filesystem\FilesystemServiceProvider;
use PHPUnit\Framework\TestCase;

final class ProfileShellTest extends TestCase
{
    private Application $app;
    private string $compiled;
    private Dispatcher $events;
    protected function setUp(): void
    {
        $this->app = new Application(dirname(__DIR__));
        Application::setInstance($this->app); Facade::clearResolvedInstances(); Facade::setFacadeApplication($this->app);
        $this->compiled = sys_get_temp_dir().'/agentools_profiles_views_'.bin2hex(random_bytes(8)); mkdir($this->compiled);
        $this->app->instance('config', new Repository(['view'=>['paths'=>[], 'compiled'=>$this->compiled]]));
        $this->events = new Dispatcher($this->app); $this->app->instance('events', $this->events);
        foreach ([FilesystemServiceProvider::class, ViewServiceProvider::class, RoutingServiceProvider::class] as $provider) { $this->app->register($provider); }
        $this->app->singleton('filament', static fn (): \Filament\FilamentManager => new \Filament\FilamentManager());
    }
    protected function tearDown(): void
    {
        foreach (glob($this->compiled.'/*') ?: [] as $file) { unlink($file); }
        rmdir($this->compiled); $this->app->flush(); Facade::clearResolvedInstances();
    }
    public function testProfilePluginSeparateOptIn(): void
    {
        $provider = new InspectorServiceProvider($this->app); $provider->register(); $provider->boot($this->events);
        $router = $this->app->make(Router::class);
        self::assertCount(0, $router->getRoutes()->getRoutes()); self::assertFalse($this->app->resolved(Admin::class));
        $read = Panel::make()->id('read'); (new InspectorPlugin())->register($read);
        self::assertSame([Inspect::class], $read->getPages());
        $write = Panel::make()->id('profiles'); (new ProfilePlugin())->register($write);
        self::assertSame([Profiles::class], $write->getPages());
        Profiles::registerRoutes($write); $routes = $router->getRoutes()->getRoutes(); self::assertCount(1, $routes);
        self::assertSame('agentools-profiles', $routes[0]->uri()); self::assertSame(['GET','HEAD'], $routes[0]->methods());
        $this->events->dispatch('Illuminate\\Queue\\Events\\JobProcessed'); self::assertFalse($this->app->resolved(Admin::class));
    }
    public function testProfileProjectionEscaped(): void
    {
        $provider = new InspectorServiceProvider($this->app); $provider->register(); $provider->boot($this->events);
        $f = new \AgenToolsPlatform\Tests\Profiles\Merge\Fixture();
        $conflict = new \AgenToolsPlatform\Core\Profiles\Composition\ProfileConflict('REGION_INTERSECTION_EMPTY', 'regions',
            [['dependency'=>$f->layer(\AgenToolsPlatform\Core\Profiles\Composition\LayerRole::Tenant)->dependency, 'value'=>'private raw value']],
            '<script>unsafe</script>', $f->json);
        $html = $this->app->make(Factory::class)->make('agentools-inspector::profile-projection', [
            'result'=>new Result('validation_failed', ['id'=>'<script>id</script>'], ['COMPILATION_FAILED'], $conflict)])->render();
        self::assertStringNotContainsString('<script>', $html); self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('REGION_INTERSECTION_EMPTY', $html); self::assertStringContainsString('sha-256:', $html);
        self::assertStringNotContainsString('private raw value', $html);
    }
}
