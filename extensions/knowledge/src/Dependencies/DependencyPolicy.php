<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Dependencies;

use AgenToolsPlatform\Knowledge\{Decision, Scope};

/** Trusted host's current tenant/subject/purpose/rights and emergency guards; no permissive default. */
interface DependencyPolicy
{
    public function check(Scope $scope, Node $node, string $operation): Decision;
}
