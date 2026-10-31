<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Runtime;

/** Infrastructure error. Retry intake with the same identity; never infer a commit outcome. */
final class StorageFailure extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('PostgreSQL persistence unavailable');
    }
}
