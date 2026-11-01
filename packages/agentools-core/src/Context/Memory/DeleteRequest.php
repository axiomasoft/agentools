<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context\Memory;

use AgenToolsPlatform\Core\Common\Ref;

/** Receipt for the owner's delete process, not fabricated completion. */
final readonly class DeleteRequest { public function __construct(public Ref $ref,public DeleteStatus $status) {} }
