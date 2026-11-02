<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Usage;

/** Pure consumer projection over supplied facts. No storage, quotas, charges or wallet. */
final class UsageFold
{
    /** @param list<UsageEvent> $events */
    public static function fold(array $events): Projection
    {
        usort($events,static fn (UsageEvent $a,UsageEvent $b): int => strcmp(self::operation($a),self::operation($b)) ?: ($a->version <=> $b->version));
        $identities = []; $ids = []; $active = []; $history = [];
        foreach ($events as $event) {
            $operation = self::operation($event); $identity = json_encode([$operation,$event->version],JSON_THROW_ON_ERROR);
            $id = json_encode([$event->id->tenant->value,$event->id->value],JSON_THROW_ON_ERROR);
            if ((isset($identities[$identity]) && $identities[$identity] !== $event->hash->value)
                || (isset($ids[$id]) && $ids[$id] !== $event->hash->value)) { throw new \AgenToolsPlatform\Core\Usage\UsageConflict(); }
            if (isset($identities[$identity])) { continue; }
            $before = $active[$operation] ?? null;
            if ($before === null ? $event->supersedes !== null : ($event->supersedes !== $before->id->value || $event->version <= $before->version)) {
                throw new \InvalidArgumentException('Missing predecessor or conflicting correction branch');
            }
            if ($before !== null && $before->binding() !== $event->binding()) { throw new \AgenToolsPlatform\Core\Usage\UsageConflict(); }
            $identities[$identity] = $event->hash->value; $ids[$id] = $event->hash->value; $history[] = $event; $active[$operation] = $event;
        }
        return new \AgenToolsPlatform\Core\Usage\Projection($history,$active);
    }
    private static function operation(UsageEvent $e): string { return json_encode([$e->id->tenant->value,$e->invocation->value],JSON_THROW_ON_ERROR); }
}
