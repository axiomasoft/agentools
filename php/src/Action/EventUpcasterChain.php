<?php

declare(strict_types=1);

namespace AgenTools\Action;

/** Applies pure event migrations only while reading; records are never mutated. */
final readonly class EventUpcasterChain
{
    /** @param list<EventUpcaster> $upcasters */
    public function __construct(private array $upcasters = [])
    {
    }

    public function upcast(ActionEvent $event): ActionEvent
    {
        while (true) {
            $upcaster = null;
            foreach ($this->upcasters as $candidate) {
                if ($candidate->supports($event)) {
                    $upcaster = $candidate;
                    break;
                }
            }
            if ($upcaster === null) {
                return $event;
            }
            $upcast = $upcaster->upcast($event);
            if ($upcast->eventVersion <= $event->eventVersion) {
                throw new LifecycleException('An event upcaster must increase event_version.');
            }
            $event = $upcast;
        }
    }
}
