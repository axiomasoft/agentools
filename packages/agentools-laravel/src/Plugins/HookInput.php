<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Plugins;

use AgenToolsPlatform\Core\Common\{Ref, SessionId};
use AgenToolsPlatform\Laravel\Managed\Entry;

/** Metadata only. No ExecutionContext capability, container, transport or owner handler. */
final readonly class HookInput
{
    public function __construct(public Phase $phase,public SessionId $session,public string $purpose,
        public Ref $inputRef,public ?Entry $entry,public ?Ref $checked=null)
    {
        $session->tenant->assertSame($inputRef->tenant);
        if ($checked!==null) { $session->tenant->assertSame($checked->tenant); }
        if ($phase===Phase::ContextData && $entry===null) { throw new \InvalidArgumentException('Context entry metadata required'); }
        if (($phase===Phase::CheckedDisclosure)!==($checked!==null)) { throw new \InvalidArgumentException('Phase-bound checked reference required'); }
    }
}
