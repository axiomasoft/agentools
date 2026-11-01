<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Security;

enum LogCode: string { case Allowed='allowed';case Denied='denied';case Unknown='unknown';case SourceRevoked='source_revoked';case Interrupted='interrupted';case Failure='failure'; }
