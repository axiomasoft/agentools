<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context\History;

enum DerivedKind: string { case Summary='summary'; case Candidate='candidate'; case HistoryIndex='history_index'; case ContextCache='context_cache'; }
