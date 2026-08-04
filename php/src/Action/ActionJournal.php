<?php

declare(strict_types=1);

namespace AgenTools\Action;

/** Append-only action journal. Its state is always derived by ActionReducer. */
final readonly class ActionJournal
{
    /** @param list<ActionEvent> $events */
    public function __construct(private array $events = [])
    {
    }

    /** @return list<ActionEvent> */
    public function events(): array
    {
        return $this->events;
    }

    public function append(ActionEvent $event, ?ActionReducer $reducer = null): self
    {
        $events = [...$this->events, $event];
        ($reducer ?? new ActionReducer())->fold($events);
        return new self($events);
    }

    public function state(?ActionReducer $reducer = null): FoldedAction
    {
        return ($reducer ?? new ActionReducer())->fold($this->events);
    }
}
