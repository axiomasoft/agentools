<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Deployments;

use AgenToolsPlatform\Core\Common\{Ref, TenantId};

/** Current host authorization for catalog, qualification, emergency operations and trace access. */
interface Authority { public function authorize(TenantId $tenant, Ref $actor, string $operation): void; }
