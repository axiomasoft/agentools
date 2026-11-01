<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Scheduler\Tests;

final class Evidence
{
    /** @param array<string,mixed> $facts */
    public static function record(string $case, array $facts): void
    {
        $path = getenv('AGENTOOLS_SCHEDULER_OBSERVATIONS');
        if ($path === false || $path === '') { return; }
        $bytes = is_file($path) ? file_get_contents($path) : false;
        $prior = $bytes === false ? [] : json_decode($bytes,true,64,JSON_THROW_ON_ERROR);
        if (!is_array($prior)) { throw new \RuntimeException('Invalid observation file'); }
        $prior[$case] = $facts;
        file_put_contents($path,json_encode($prior,JSON_THROW_ON_ERROR));
    }
}
