<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context\History;

use AgenToolsPlatform\Core\Common\{CanonicalHasher,PayloadHash,Ref};

/** Complete coverage is attested by the external transcript owner, never an extractor. */
final readonly class SourceManifest
{
    public PayloadHash $hash;
    /** @var list<MessageRef> */ public array $messages;
    /** @param array<array-key,MessageRef> $messages */
    public function __construct(public Ref $branch,public int $throughTurn,public SourceStatus $status,public bool $complete,
        array $messages,CanonicalHasher $hasher)
    {
        if ($throughTurn<0 || $throughTurn>9007199254740991 || !array_is_list($messages) || count($messages)>4096
            || ($status!==SourceStatus::Available && ($complete || $messages!==[]))) { throw new \InvalidArgumentException('Explicit bounded owner coverage required'); }
        $ids=[];$previous=0;
        foreach ($messages as $message) {
            $branch->assertSame($message->branch);
            if ($message->turn>$throughTurn || $message->turn<$previous || isset($ids[$message->ref->value])) { throw new \InvalidArgumentException('Ordered unique exact source refs required'); }
            $ids[$message->ref->value]=true;$previous=$message->turn;
        }
        $this->messages=$messages;
        $this->hash=$hasher->hash($branch->tenant,'history-source-manifest',$this->toArray());
    }
    /** @return array<string,mixed> */
    public function toArray(): array { return ['branch'=>$this->branch->toArray(),'through_turn'=>$this->throughTurn,'status'=>$this->status->value,'complete'=>$this->complete,'messages'=>array_map(static fn(MessageRef $r): array=>$r->toArray(),$this->messages)]; }
}
