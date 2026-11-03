<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Laravel\Usage;

use AgenToolsPlatform\Core\Common\TenantId;
use AgenToolsPlatform\Postgres\Budget\Reservations;
use AgenToolsPlatform\Postgres\Usage\Recorder;

/** Explicit host-scoped opt-in; no default money/metering implementation or unlimited profile. */
final readonly class Accounting
{
    public function __construct(public TenantId $tenant, public Reservations $budget, public Recorder $recorder)
    { $tenant->assertSame($recorder->repository->tenant); $budget->assertRepository($recorder->repository); }
}
