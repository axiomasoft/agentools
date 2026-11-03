<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Core\Observability;

use AgenToolsPlatform\Core\Common\{CanonicalHasher,PayloadHash,Ref,ContractViolation,IntegrityConflict,ErrorCode};
use AgenToolsPlatform\Core\Security\{EntryPoint,ExecutionContext,LogCode,LogProjection,TraceLinks};

/** Immutable metadata only. Never serialize an exception graph, raw request or active authorization snapshot. */
final readonly class Event implements \JsonSerializable
{
    public PayloadHash $hash;
    /** @var array<string,mixed> */ private array $record;
    /** @param array<string,mixed> $untrusted */
    public function __construct(public Ref $id,private ExecutionContext $context,public Stage $stage,
        public Outcome $outcome,public EntryPoint $entry,TraceLinks $links,CanonicalHasher $hasher,
        #[\SensitiveParameter] array $untrusted=[],#[\SensitiveParameter] ?\Throwable $error=null)
    {
        $id->tenant->assertSame($context->session->tenant);
        $code=match($outcome) { Outcome::Denied=>LogCode::Denied,Outcome::Failed=>LogCode::Failure,
            Outcome::Unknown=>LogCode::Unknown,Outcome::Interrupted=>LogCode::Interrupted,default=>LogCode::Allowed };
        // Never forward sensitive arguments into another stack frame, even on a rejected link.
        $metadata=LogProjection::record($context,$code,$links);
        $metadata['error_code']=match(true) { $error===null=>null,$error instanceof ContractViolation=>$error->errorCode->value,
            $error instanceof IntegrityConflict=>ErrorCode::IntegrityConflict->value,default=>'internal_error' };
        $metadata['redacted']=$untrusted!==[] || $error!==null;
        $this->record=$metadata+[
            'event_id'=>$id->value,'actor_ref'=>$context->identity->actorRef->toArray(),
            'scope_hash'=>$context->scopeHash($hasher)->value,'stage'=>$stage->value,'outcome'=>$outcome->value,'entry'=>$entry->value];
        $this->hash=$hasher->hash($id->tenant,'observability-event',$this->record);
    }
    public function context(): ExecutionContext { return $this->context; }
    /** @return array<string,mixed> */
    public function metadata(): array { return $this->record; }
    /** @return array<string,mixed> */
    public function jsonSerialize(): array { return $this->record; }
    /** @return array<string,mixed> */
    public function __debugInfo(): array { return $this->record; }
}
