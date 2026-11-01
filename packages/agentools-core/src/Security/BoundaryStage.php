<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Security;

enum BoundaryStage: string { case Invocation='invocation';case Tool='tool';case Disclosure='disclosure'; }
