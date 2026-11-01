<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Skills;

enum ContentKind: string
{
    case Fragment = 'skill_fragment';
    case Secret = 'secret';
    case History = 'history';
    case Corpus = 'corpus';
    case Curriculum = 'curriculum';
    case ToolCatalogue = 'tool_catalogue';
}
