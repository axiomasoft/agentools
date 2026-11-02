<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Usage;

enum Certainty: string { case Preliminary = 'preliminary'; case Final = 'final'; case Partial = 'partial'; case Unknown = 'unknown'; }
