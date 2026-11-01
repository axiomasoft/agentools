<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Skills;

use AgenToolsPlatform\Core\Profiles\{DefinitionRef, ResourceRef};

interface TokenCounter
{
    public function revision(): DefinitionRef;
    public function deployment(): ResourceRef;
    public function estimate(string $text): TokenEstimate;
}
