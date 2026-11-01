<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Definitions;

use AgenToolsPlatform\Core\Common\{InstanceId, Ref};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Runtime\{SessionBinding, SessionRepository};

/** Reuses existing immutable platform session bindings; bundle publication is external to this slice. */
final readonly class SessionBinder
{
    public function __construct(private PostgresInstances $instances,private Registry $registry,
        private Availability $availability,private SessionRepository $sessions) {}
    public function bind(ExecutionContext $context,InstanceId $id,?Ref $history=null): SessionPin
    {
        $pin=$this->instances->pin($context,$id);
        $this->registry->current($pin->definition,$context,$this->availability);
        $hash=$pin->definition->version->hash;
        $this->sessions->bind($context,new SessionBinding($context->session,$id,
            new Ref($id->tenant,'assistant-definition:'.substr($hash->value,8)),$hash,$hash,$pin->bundle->ref,$pin->bundle->hash,$history));
        return $pin;
    }
}
