<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Core\Models\Routing;
use AgenToolsPlatform\Core\Common\{IntegrityConflict,Ref};

/** Existing host state and accepted Action refs survive a replacement Invocation unchanged. */
final readonly class OutputContext
{
    /** @var list<Ref> */ public array $actions;
    /** @param array<array-key,Ref> $actions */
    public function __construct(public Ref $bundle,public Ref $goal,public Ref $workingState,public Ref $businessOperation,array $actions)
    {
        if (!array_is_list($actions)) { throw new \InvalidArgumentException('Ordered accepted Action refs required'); }
        $this->actions=$actions;
        foreach ([$goal,$workingState,$businessOperation,...$actions] as $ref) { $bundle->tenant->assertSame($ref->tenant); }
    }
    public function assertSame(self $other): void
    {
        $this->bundle->assertSame($other->bundle); $this->goal->assertSame($other->goal); $this->workingState->assertSame($other->workingState);
        $this->businessOperation->assertSame($other->businessOperation);
        if (count($this->actions)!==count($other->actions)) { throw new \AgenToolsPlatform\Core\Common\IntegrityConflict('Fallback changed accepted Actions'); }
        foreach ($this->actions as $i=>$ref) { $ref->assertSame($other->actions[$i]); }
    }
}
