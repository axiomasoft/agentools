<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Chatom;

use AgenToolsPlatform\Core\Common\Ref;
use Chatom\Actors\Data\ActorRef;
use Chatom\Channels\Data\ChannelId;
use Chatom\Spaces\Data\SpaceId;

/** Rehydrated by the host from current owning authority; never accepted from a client. */
final readonly class TranscriptBinding
{
    public function __construct(public Ref $branch, public SpaceId $space, public ChannelId $channel,
        public ActorRef $viewer, public string $assistant, public string $nativeTenant)
    {
        if ($assistant === '' || $nativeTenant === '') {
            throw new \InvalidArgumentException('An exact transcript binding is required');
        }
    }
}
