<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models\Routing;

use AgenToolsPlatform\Core\Common\UtcInstant;

interface Clock { public function now(): UtcInstant; }
