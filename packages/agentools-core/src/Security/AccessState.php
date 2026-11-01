<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Security;

enum AccessState: string { case Allow='allow';case Deny='deny';case Unknown='unknown'; }
