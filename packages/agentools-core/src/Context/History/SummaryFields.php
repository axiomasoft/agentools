<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context\History;

use AgenToolsPlatform\Core\Common\Ref;

/** Extracted assertions remain refs to untrusted derived data, never owner domain facts. */
final readonly class SummaryFields
{
    /**
     * @param array<array-key,Ref> $covered
     * @param array<array-key,Ref> $understood
     * @param array<array-key,Ref> $uncertain
     * @param array<array-key,Ref> $openQuestions
     * @param array<array-key,Ref> $promises
     * @param array<array-key,Ref> $artifacts
     * @param array<array-key,Ref> $actions
     */
    public function __construct(public Ref $viewRef,public array $covered=[],public array $understood=[],public array $uncertain=[],
        public array $openQuestions=[],public array $promises=[],public array $artifacts=[],public array $actions=[])
    {
        foreach ([$covered,$understood,$uncertain,$openQuestions,$promises,$artifacts,$actions] as $refs) { Checks::refs($viewRef,$refs); }
    }
    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $values=['covered'=>$this->covered,'understood'=>$this->understood,'uncertain'=>$this->uncertain,'open_questions'=>$this->openQuestions,
            'promises'=>$this->promises,'artifact_refs'=>$this->artifacts,'action_refs'=>$this->actions];$out=['view_ref'=>$this->viewRef->toArray()];
        foreach ($values as $key=>$refs) { $out[$key]=array_map(static fn(Ref $r): array=>$r->toArray(),$refs); } return $out;
    }
}
