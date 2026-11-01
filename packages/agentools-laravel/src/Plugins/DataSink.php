<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Plugins;

use AgenToolsPlatform\Core\Security\TrustedInput;

interface DataSink
{
    /** Verify exact bytes/hash, CURRENT source ACL and classification; add as untrusted data only. */
    public function accept(TrustedInput $input,Contribution $contribution): void;
    public function reset(): void;
}
