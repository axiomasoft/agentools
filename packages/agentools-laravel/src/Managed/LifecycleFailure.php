<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Managed;

/** Fail closed: a worker must be retired when its scoped cleanup cannot be proven. */
final class LifecycleFailure extends \RuntimeException
{
    public function __construct() { parent::__construct('Managed lifecycle reset failed'); }
}
