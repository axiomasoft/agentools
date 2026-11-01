<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Composition;

use AgenToolsPlatform\Core\Profiles\ResourceKind;

enum LayerRole: int
{
    case Definition = 0;
    case Platform = 1;
    case Tenant = 2;
    case Profile = 3;
    case Skill = 4;
    case Lesson = 5;

    public function resourceKind(): ResourceKind
    {
        return match ($this) {
            self::Definition=>ResourceKind::Definition, self::Profile=>ResourceKind::Profile,
            self::Skill=>ResourceKind::Skill, default=>ResourceKind::ExecutionPolicy,
        };
    }
}
