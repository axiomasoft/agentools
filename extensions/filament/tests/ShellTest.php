<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Filament\Tests;

use AgenToolsPlatform\Filament\{Inspection, Inspector, InspectorPlugin, InspectorServiceProvider, Pages\Inspect};
use AgenToolsPlatform\Laravel\AgenToolsServiceProvider;
use Filament\Panel;
use Illuminate\Config\Repository;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Routing\RoutingServiceProvider;
use Illuminate\Routing\Router;
use Illuminate\View\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\View\ViewServiceProvider;
use PHPUnit\Framework\TestCase;

final class ShellTest extends TestCase
{
    private Application $app;
    private string $compiled;
    private Dispatcher $events;
    private Router $router;
    protected function setUp(): void
    {
        $this->app = new Application(dirname(__DIR__));
        Application::setInstance($this->app); Facade::clearResolvedInstances(); Facade::setFacadeApplication($this->app);
        $this->compiled = sys_get_temp_dir().'/agentools_admin_views_'.bin2hex(random_bytes(8));
        mkdir($this->compiled);
        $this->app->instance('config', new Repository(['view' => ['paths' => [], 'compiled' => $this->compiled]]));
        $this->events = new Dispatcher($this->app);
        $this->app->instance('events', $this->events);
        $this->app->register(FilesystemServiceProvider::class);
        $this->app->register(ViewServiceProvider::class);
        $this->app->register(RoutingServiceProvider::class);
        $this->router = $this->app->make(Router::class);
        $this->app->singleton('filament', static fn (): \Filament\FilamentManager => new \Filament\FilamentManager());
    }
    protected function tearDown(): void
    {
        foreach (glob($this->compiled.'/*') ?: [] as $file) { unlink($file); }
        rmdir($this->compiled); $this->app->flush(); Facade::clearResolvedInstances();
    }
    public function testProviderAndPluginOptIn(): void
    {
        $headless = new AgenToolsServiceProvider($this->app); $headless->register(); $headless->boot($this->events);
        self::assertCount(0, $this->router->getRoutes()->getRoutes());
        self::assertFalse($this->app->bound(Inspector::class));
        $provider = new InspectorServiceProvider($this->app); $provider->register(); $provider->boot($this->events);
        self::assertCount(0, $this->router->getRoutes()->getRoutes());
        self::assertTrue($this->app->bound(Inspector::class));
        $panel = Panel::make()->id('host-admin');
        self::assertSame([], $panel->getPages());
        $plugin = new InspectorPlugin(); $plugin->register($panel); $plugin->boot($panel);
        self::assertSame([Inspect::class], $panel->getPages());
        Inspect::registerRoutes($panel);
        $routes = $this->router->getRoutes()->getRoutes();
        self::assertCount(1, $routes);
        foreach ($routes as $route) {
            self::assertSame('agentools-inspect', $route->uri());
            self::assertSame(['GET','HEAD'], $route->methods());
            self::assertSame(Inspect::class, $route->getActionName());
        }
        // Unused lifecycle cleanup must not construct host bindings or a reader.
        $this->events->dispatch('Illuminate\\Queue\\Events\\JobProcessed');
        self::assertFalse($this->app->resolved(Inspector::class));
    }
    public function testEscapedProjection(): void
    {
        $provider = new InspectorServiceProvider($this->app); $provider->register(); $provider->boot($this->events);
        $view = $this->app->make(Factory::class)->make('agentools-inspector::projection', [
            'inspection' => new Inspection('ok', ['id' => '<script>alert(1)</script>', 'usage' => null, 'zero' => 0])]);
        $html = $view->render();
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('unknown / not observed', $html);
        self::assertStringContainsString('<dd>0</dd>', $html);
    }
}
