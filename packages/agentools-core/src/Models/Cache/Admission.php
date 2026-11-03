<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Core\Models\Cache;
use AgenToolsPlatform\Core\Common\UtcInstant;

interface Admission
{
    /** Ordinary current owner admission on both hit and rebuild. A cache never supplies billable permission. */
    public function admit(Prepared $prepared,UtcInstant $now): void;
}
