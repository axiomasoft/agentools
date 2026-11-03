<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Laravel\Usage;

use AgenToolsPlatform\Core\Common\TenantId;
use AgenToolsPlatform\Postgres\Runtime\Connection;
use AgenToolsPlatform\Postgres\Usage\Dispatcher;

/** Long-lived queue/Octane entrypoint: never retain a tenant repository between jobs. */
final readonly class Worker
{
    /** @param \Closure(TenantId): Dispatcher $factory */
    public function __construct(private Connection $connection, private \Closure $factory) {}
    public function run(TenantId $tenant): bool
    {
        $this->connection->resetSession();
        try {
            $dispatcher=($this->factory)($tenant); $dispatcher->assertScope($tenant,$this->connection);
            return $dispatcher->dispatchOne();
        }
        finally { $this->connection->resetSession(); }
    }
}
