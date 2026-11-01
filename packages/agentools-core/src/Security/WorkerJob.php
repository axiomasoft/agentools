<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Security;

use AgenToolsPlatform\Core\Common\Ref;

interface WorkerJob { public function run(ExecutionContext $context): Ref; }
