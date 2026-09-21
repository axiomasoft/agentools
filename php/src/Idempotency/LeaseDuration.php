<?php

declare(strict_types=1);

namespace AgenTools\Idempotency;

/** RL-015/RL-016 lease bounds. */
final class LeaseDuration
{
    public const int DEFAULT_SECONDS = 30;
    public const int MIN_SECONDS = 1;
    public const int MAX_SECONDS = 300;

    public static function seconds(\DateInterval $interval): int
    {
        return (new \DateTimeImmutable('@0'))->add($interval)->getTimestamp();
    }

    public static function assertValid(\DateInterval $interval): int
    {
        $seconds = self::seconds($interval);
        if ($seconds < self::MIN_SECONDS || $seconds > self::MAX_SECONDS) {
            throw new \InvalidArgumentException('Lease duration must be between 1 and 300 seconds.');
        }

        return $seconds;
    }

    public static function default(): \DateInterval
    {
        return new \DateInterval('PT' . self::DEFAULT_SECONDS . 'S');
    }
}
