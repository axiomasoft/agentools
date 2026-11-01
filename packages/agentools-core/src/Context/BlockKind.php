<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context;

enum BlockKind: string
{
    case Policy='policy'; case Profile='profile'; case Skill='skill'; case Tools='tools';
    case Goal='goal'; case WorkingState='working_state'; case Promise='promise'; case Subject='subject';
    case Evidence='evidence'; case ToolResult='tool_result'; case Summary='summary'; case Recent='recent';
    case Input='input'; case Example='example';
}
