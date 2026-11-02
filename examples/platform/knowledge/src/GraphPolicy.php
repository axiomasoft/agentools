<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\Knowledge;

use AgenToolsPlatform\Knowledge\{Decision,Scope};
use AgenToolsPlatform\Knowledge\Dependencies\{DependencyPolicy,Node};

final readonly class GraphPolicy implements DependencyPolicy
{
    public function __construct(private Owner $owner) {}
    public function check(Scope $scope, Node $node, string $operation): Decision
    { return $scope->tenant===$node->tenant ? $this->owner->current($scope) : Decision::Denied; }
}
