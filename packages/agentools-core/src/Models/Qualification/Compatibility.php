<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Core\Models\Qualification;
use AgenToolsPlatform\Core\Common\Ref;
/** Trusted host validates previous/new deployment+bundle compatibility and current material access. */
interface Compatibility { public function assertCompatible(Snapshot $previous, Snapshot $target, Ref $reason): void; }
