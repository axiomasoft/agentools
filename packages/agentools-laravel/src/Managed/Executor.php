<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Managed;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, IntegrityConflict};
use Illuminate\Container\Container;

/** Stateless facade: each entry resolves fresh scoped host ports and clears them in finally. */
final readonly class Executor
{
    public function __construct(private Container $app, private Scope $scope) {}

    public function execute(Input $input): DeliveryReceipt
    {
        try { return $this->executeScoped($input); }
        catch (LifecycleFailure) { $this->scope->poison(); throw new LifecycleFailure(); }
        catch (ContractViolation $error) { throw new ContractViolation($error->errorCode); }
        catch (IntegrityConflict) { throw new IntegrityConflict('Managed binding integrity conflict'); }
        catch (\Throwable) { throw new ContractViolation(ErrorCode::PolicyConflict); }
    }

    private function executeScoped(Input $input): DeliveryReceipt
    {
        $scope = $this->scope;
        $scope->assertAvailable();
        // HostBindings is scoped but must not survive a completed entry, even inside one HTTP request.
        $ports = $this->app->make(HostBindings::class);
        $scope->enter($ports);
        try {
            $ports->reset();
            $ports->assertEnvironment($this->app);
            return (new Composition($ports, $scope))->execute($input);
        } finally {
            try { $scope->reset(); }
            finally { self::forget($this->app); }
        }
    }

    public static function forget(Container $app): void
    {
        foreach ([HostBindings::class, ...array_keys(HostBindings::PORTS)] as $binding) { $app->forgetInstance($binding); }
    }
}
