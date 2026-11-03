<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Core\Models\Cache;
use AgenToolsPlatform\Core\Common\UtcInstant;

interface Rebuilder
{
    /** Use current authorized sources and the existing compiler finalization; refuse mandatory overflow. */
    public function rebuild(Key $requested,UtcInstant $now): Prepared;
}
