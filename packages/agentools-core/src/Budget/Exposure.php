<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Budget;

enum Exposure: string { case NotSent = 'not_sent'; case Known = 'known'; case Unknown = 'unknown'; }
