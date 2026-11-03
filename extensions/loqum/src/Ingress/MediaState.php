<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Loqum\Ingress;

use AgenToolsPlatform\Core\Common\Ref;

/** External media owner fence, separate from AssistantSession and pinned compiler state. */
final readonly class MediaState
{
    public function __construct(public Ref $realtimeSession, public int $epoch, public int $contextRevision)
    {
        foreach ([$epoch, $contextRevision] as $version) {
            if ($version < 1 || $version > 9007199254740991) {
                throw new \InvalidArgumentException('Positive interoperable media/context version required');
            }
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['realtime_session_ref' => $this->realtimeSession->toArray(),
            'media_epoch' => $this->epoch, 'context_revision' => $this->contextRevision];
    }
}
