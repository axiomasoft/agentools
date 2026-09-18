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

    /** @param list<ActionEvent> $tail */
    public static function restore(ActionSnapshot $snapshot, array $tail, ?ActionReducer $reducer = null): FoldedAction
    {
        $reducer ??= new ActionReducer($snapshot->specRevision);
        $state = $snapshot->state;
        foreach ($tail as $event) {
            $state = $reducer->apply($state, $event);
        }
        return $state;
    }
}
