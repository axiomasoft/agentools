<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context\History;

use AgenToolsPlatform\Core\Security\ExecutionContext;

interface SummaryViews { public function read(ExecutionContext $context,SummaryVersion $summary): TextRead; }
