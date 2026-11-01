<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Security;

use AgenToolsPlatform\Core\Common\{PayloadHash,Ref};

interface DisclosureInspector
{
    /** Full result for buffered, full closed segment for checked_segments. */
    public function check(ExecutionContext $context,Ref $response,string $bytes,PayloadHash $hash,bool $responseComplete): DisclosureCheck;
}
