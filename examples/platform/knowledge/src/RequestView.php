<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\Knowledge;

use AgenToolsPlatform\Core\Common\IntegrityConflict;
use AgenToolsPlatform\Core\Models\{Deployment,ModelRequest};
use AgenToolsPlatform\Core\Runtime\RequestRenderer;

/** Concrete representation for this single-message scripted adapter, bound to the compiler renderer. */
final readonly class RequestView implements RequestRenderer
{
    public function __construct(private KnowledgeJourney $journey) {}
    public function version(): string { return ($this->journey->guardSnapshot ?? throw new \LogicException('Prepare context first'))->rendererVersion(); }
    public function renderRequest(ModelRequest $request, Deployment $deployment): string
    {
        $text=$request->messages[0]->parts[0]->text ?? null;
        if (count($request->messages)!==1 || count($request->messages[0]->parts)!==1 || $request->tools!==[]
            || $text!==($this->journey->compiled ?? throw new \LogicException('Prepare context first'))->rendered) {
            throw new IntegrityConflict('Scripted request representation changed');
        }
        return $text;
    }
}
