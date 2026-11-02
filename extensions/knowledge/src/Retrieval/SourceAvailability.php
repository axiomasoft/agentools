<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Retrieval;

use AgenToolsPlatform\Knowledge\{Decision, Material, Scope};

interface SourceAvailability
{
    /** Current exact Files/Vaulter ref/version/hash existence, scan/hold/retention. No source-byte loading. */
    public function check(Scope $scope, Material $material): Decision;
}
