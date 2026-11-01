<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Scheduler;

use AgenToolsPlatform\Core\Common\UtcInstant;

/** Local calendar selection only; no network, job dispatch or runtime planning. */
final class Planner
{
    /** @return list<UtcInstant> */
    public function due(Schedule $schedule, UtcInstant $after, UtcInstant $through): array
    {
        $lower = new \DateTimeImmutable($after->value); $upper = new \DateTimeImmutable($through->value);
        if ($upper <= $lower) { return []; }
        $start = new \DateTimeImmutable($schedule->startsAt->value); $end = new \DateTimeImmutable($schedule->endsAt->value);
        if ($upper < $start || $lower >= $end) { return []; }
        $zone = new \DateTimeZone($schedule->timezone);
        $first = max($lower, $start)->setTimezone($zone)->format('Y-m-d');
        $last = min($upper, $end)->setTimezone($zone)->format('Y-m-d');
        $date = new \DateTimeImmutable($first.'T00:00:00Z');
        $slots = [];
        for ($day = 0; $date->format('Y-m-d') <= $last; ++$day, $date = $date->modify('+1 day')) {
            if ($day > 3662) { throw new InspectionFailure('scan_bound_exceeded'); }
            $slot = $this->wall($date->format('Y-m-d'), $schedule);
            if ($slot === null) { continue; }
            $instant = new \DateTimeImmutable($slot->value);
            if ($instant <= $lower || $instant > $upper || $instant < $start || $instant >= $end
                || ($schedule->misfire === 'skip' && $instant < $upper->modify('-'.$schedule->graceSeconds.' seconds'))) { continue; }
            if ($schedule->misfire === 'one') { return [$slot]; }
            $slots[] = $slot;
            $limit = $schedule->misfire === 'latest' ? 1 : $schedule->catchupLimit;
            if (count($slots) > $limit) { array_shift($slots); }
        }
        return $slots;
    }

    public function wall(string $date, Schedule $schedule): ?UtcInstant
    {
        $wall = $date.' '.$schedule->wallTime;
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $wall, new \DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m-d H:i:s') !== $wall) { throw new \InvalidArgumentException('Invalid wall date'); }
        $zone = new \DateTimeZone($schedule->timezone); $stamp = $parsed->getTimestamp();
        $transitions = $zone->getTransitions($stamp - 2 * 86400, $stamp + 2 * 86400);
        $candidates = [];
        foreach ($transitions as $transition) {
            $candidate = (new \DateTimeImmutable('@'.($stamp - $transition['offset'])))->setTimezone($zone);
            if ($candidate->format('Y-m-d H:i:s') === $wall) { $candidates[$candidate->getTimestamp()] = $candidate; }
        }
        ksort($candidates); $values = array_values($candidates);
        if ($values === []) {
            if ($schedule->gap === 'reject') { throw new InspectionFailure('dst_gap'); }
            return null;
        }
        if (count($values) > 1 && $schedule->overlap === 'reject') { throw new InspectionFailure('dst_overlap'); }
        $selected = $schedule->overlap === 'choose-last' ? $values[count($values) - 1] : $values[0];
        return new UtcInstant($selected->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'));
    }
}
