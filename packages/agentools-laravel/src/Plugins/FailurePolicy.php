<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Plugins;

enum FailurePolicy: string { case Abort='abort'; case Omit='omit_optional'; }
