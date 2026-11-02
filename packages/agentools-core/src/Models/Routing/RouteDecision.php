<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models\Routing;

final readonly class RouteDecision
{
    /** @param array<string,list<Reason>> $rejected */
    public function __construct(public ?CatalogEntry $selected, public array $rejected) {}
    public function outcome(): string { return $this->selected === null ? 'non_ai_text' : 'selected'; }
}
