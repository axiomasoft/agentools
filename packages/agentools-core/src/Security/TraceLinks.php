<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Security;

use AgenToolsPlatform\Core\Common\{PayloadHash,Ref};

/** Trusted technical values only; arbitrary arrays/provider exceptions are not log metadata. */
final readonly class TraceLinks
{
    public function __construct(public ?Ref $turn=null,public ?Ref $run=null,public ?Ref $snapshot=null,public ?Ref $invocation=null,
        public ?Ref $action=null,public ?Ref $effect=null,public ?Ref $delivery=null,public ?Ref $model=null,public ?Ref $decision=null,
        public ?Ref $usage=null,public ?Ref $diagnostic=null,public ?PayloadHash $hash=null,public ?int $durationMs=null)
    { if ($durationMs!==null && $durationMs<0) { throw new \InvalidArgumentException('Duration is nonnegative or unknown'); } }
    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $out=[];foreach (['turn'=>$this->turn,'run'=>$this->run,'snapshot'=>$this->snapshot,'invocation'=>$this->invocation,'action'=>$this->action,
            'effect'=>$this->effect,'delivery'=>$this->delivery,'model'=>$this->model,'decision'=>$this->decision,'usage'=>$this->usage,'diagnostic'=>$this->diagnostic] as $key=>$ref) {
            if ($ref!==null) { $out[$key.'_ref']=$ref->toArray(); }
        }return $out+['hash'=>$this->hash?->value,'duration_ms'=>$this->durationMs];
    }
}
