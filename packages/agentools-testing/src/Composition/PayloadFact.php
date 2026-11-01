<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Testing\Composition;

use AgenToolsPlatform\Core\Common\{PayloadHash,SessionId};

final readonly class PayloadFact
{
    public function __construct(public SessionId $session,public PayloadHash $scope,#[\SensitiveParameter] public string $json) {}
}
