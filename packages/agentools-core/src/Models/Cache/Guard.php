<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Core\Models\Cache;
use AgenToolsPlatform\Core\Common\UtcInstant;

interface Guard
{
    /** Recheck current ACL, material revoke, consent, deployment emergency and storage policy. Throws on denial. */
    public function current(Key $key,UtcInstant $now): Rights;
}
