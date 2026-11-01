<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context;

enum SourceKind: string
{
    case Host='host'; case Policy='policy'; case Skill='skill'; case History='history';
    case Retrieval='retrieval'; case Tool='tool'; case Summary='summary'; case Memory='memory'; case Input='input';
}
