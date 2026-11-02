<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge;

interface MaterialAccessPolicy
{
    /** Current owner permissions, license, hold/revoke and emergency guards; unknown fails closed. */
    public function check(Scope $scope, Material $material, string $operation): Decision;
}
