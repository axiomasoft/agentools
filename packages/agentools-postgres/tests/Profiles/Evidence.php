<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Tests\Profiles;

final class Evidence
{
    /** @param array<string, mixed> $observation */
    public static function put(string $id, array $observation): void
    {
        $path = getenv('AGENTOOLS_PROFILE_OBSERVATIONS');
        if (!is_string($path) || $path === '') { throw new \RuntimeException('Observation output is required'); }
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR) : [];
        if (!is_array($data)) { throw new \RuntimeException('Invalid observations'); }
        $data[$id] = $observation;
        if (file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR)) === false) { throw new \RuntimeException('Observation write failed'); }
    }
}
