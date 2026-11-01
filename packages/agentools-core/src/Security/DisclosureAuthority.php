<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Security;

use AgenToolsPlatform\Core\Common\Ref;

/** Trusted output classification/risk policy; model/style data never supplies this value. */
interface DisclosureAuthority { public function policy(ExecutionContext $context,SnapshotEvidence $snapshot,Ref $response): DisclosurePolicy; }
