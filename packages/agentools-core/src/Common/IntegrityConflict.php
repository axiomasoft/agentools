<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Common;

/** A previously accepted tenant-scoped identity has a different content hash. */
final class IntegrityConflict extends \DomainException
{
}
