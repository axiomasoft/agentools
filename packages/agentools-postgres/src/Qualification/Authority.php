<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Postgres\Qualification;
use AgenToolsPlatform\Core\Common\{Ref, TenantId};
/** Current host administrative authorization, independent of certificate content. */
interface Authority { public function authorize(TenantId $tenant, Ref $actor, string $operation): void; }
