<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Scheduler;

use AgenToolsPlatform\Core\Common\{InstanceId, Ref, SessionId, TenantId, UtcInstant};

final class Codec
{
    /** @return array<string,mixed> */
    public static function object(string $json): array
    {
        $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($value) || array_is_list($value)) { throw new InspectionFailure('invalid_record'); }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) { throw new InspectionFailure('invalid_record'); }
            $result[$key] = $item;
        }
        return $result;
    }

    /** @param array<string,mixed> $data */
    public static function text(array $data, string $key): string
    {
        if (!isset($data[$key]) || !is_string($data[$key])) { throw new InspectionFailure('invalid_record'); }
        return $data[$key];
    }

    /** @param array<string,mixed> $data */
    public static function number(array $data, string $key): int
    {
        if (!isset($data[$key]) || !is_int($data[$key])) { throw new InspectionFailure('invalid_record'); }
        return $data[$key];
    }

    public static function schedule(string $json): Schedule
    {
        $d = self::object($json); $tenant = new TenantId(self::text($d, 'tenant'));
        $subject = $d['subject'] ?? null;
        $actingAs = $d['acting_as'] ?? null; $principal = $d['agent_principal'] ?? null;
        foreach ([$subject,$actingAs,$principal] as $ref) {
            if ($ref !== null && !is_string($ref)) { throw new InspectionFailure('invalid_record'); }
        }
        if (($subject !== null && !is_string($subject)) || ($actingAs !== null && !is_string($actingAs))
            || ($principal !== null && !is_string($principal))) { throw new InspectionFailure('invalid_record'); }
        return new Schedule(new Ref($tenant, self::text($d, 'id')), self::number($d, 'version'),
            new InstanceId($tenant, self::text($d, 'instance')), new SessionId($tenant, self::text($d, 'session')),
            new Ref($tenant, self::text($d, 'actor')), $subject === null ? null : new Ref($tenant, $subject),
            self::text($d, 'purpose'), new Ref($tenant, self::text($d, 'template')), self::text($d, 'timezone'),
            self::text($d, 'wall_time'), new UtcInstant(self::text($d, 'starts_at')), new UtcInstant(self::text($d, 'ends_at')),
            self::text($d, 'overlap'), self::text($d, 'gap'), self::text($d, 'misfire'),
            self::number($d, 'catchup_limit'), self::number($d, 'grace_seconds'),
            $actingAs === null ? null : new Ref($tenant,$actingAs), $principal === null ? null : new Ref($tenant,$principal));
    }

    /** @return list<UtcInstant> */
    public static function slots(string $json): array
    {
        $value = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($value) || !array_is_list($value) || count($value) > 100) { throw new InspectionFailure('invalid_record'); }
        $slots = [];
        foreach ($value as $item) {
            if (!is_string($item)) { throw new InspectionFailure('invalid_record'); }
            $slots[] = new UtcInstant($item);
        }
        return $slots;
    }
}
