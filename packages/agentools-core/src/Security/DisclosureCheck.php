<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Security;

use AgenToolsPlatform\Core\Common\{PayloadHash,Ref};

final readonly class DisclosureCheck
{
    public function __construct(public Ref $ref,public AccessState $access,public PayloadHash $hash,public bool $complete) {}
}
