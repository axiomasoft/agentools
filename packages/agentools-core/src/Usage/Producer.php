<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Usage;

enum Producer: string { case Transport = 'transport'; case SdkHook = 'sdk_hook'; }
