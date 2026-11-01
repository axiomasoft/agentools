<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context\History;

use AgenToolsPlatform\Core\Common\Ref;

/** Model/adapter proposal: host acceptance is required before assigning state. */
final readonly class WorkingProposal
{
    /**
     * @param array<array-key,Ref> $promises
     * @param array<array-key,Ref> $actionRefs
     */
    public function __construct(public Ref $branch,public ?Ref $goal,public ?Ref $currentStep,public ?Ref $openQuestion,
        public ?Ref $latestResult,public ?Ref $activeUiObject,public array $promises=[],public array $actionRefs=[])
    {
        Checks::refs($branch,$promises,32); Checks::refs($branch,$actionRefs,32);
        foreach ([$goal,$currentStep,$openQuestion,$latestResult,$activeUiObject] as $ref) { if ($ref!==null) { $branch->tenant->assertSame($ref->tenant); } }
    }
    /** @return array<string,mixed> */
    public function toArray(): array { return ['branch'=>$this->branch->toArray(),'goal_ref'=>$this->goal?->toArray(),'current_step_ref'=>$this->currentStep?->toArray(),
        'open_question_ref'=>$this->openQuestion?->toArray(),'latest_result_ref'=>$this->latestResult?->toArray(),'active_ui_object_ref'=>$this->activeUiObject?->toArray(),
        'promise_refs'=>array_map(static fn(Ref $r): array=>$r->toArray(),$this->promises),'action_refs'=>array_map(static fn(Ref $r): array=>$r->toArray(),$this->actionRefs)]; }
}
