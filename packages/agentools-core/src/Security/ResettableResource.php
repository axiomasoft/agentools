<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Security;

interface ResettableResource { public function kind(): ResourceKind;public function reset(): void; }
