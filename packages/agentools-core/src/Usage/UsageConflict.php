<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Usage;

/** Caller quarantines the delivery; the original immutable fact remains unchanged. */
final class UsageConflict extends \RuntimeException
{
    public function __construct() { parent::__construct('USAGE_PAYLOAD_CONFLICT'); }
}
