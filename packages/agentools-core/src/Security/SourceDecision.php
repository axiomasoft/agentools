<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Security;

use AgenToolsPlatform\Core\Common\Ref;

final readonly class SourceDecision { public function __construct(public Ref $ref,public AccessState $access) {} }
