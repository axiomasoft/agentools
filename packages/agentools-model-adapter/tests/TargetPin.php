<?php

declare(strict_types=1);

namespace AgenToolsPlatform\ModelAdapterTests;

use Composer\InstalledVersions;

final class TargetPin
{
    public static function verify(mixed $lock): void
    {
        if (!is_array($lock) || !is_array($lock['packages'] ?? null) || $lock['packages'] === []) { throw new \RuntimeException('Missing target lock'); }
        foreach ($lock['packages'] as $package) {
            if (!is_array($package) || !is_string($package['name'] ?? null) || !is_string($package['version'] ?? null)) { throw new \RuntimeException('Invalid target pin'); }
            $name = $package['name'];
            $source = $package['source'] ?? $package['dist'] ?? null;
            if (!is_array($source) || !is_string($source['reference'] ?? null) || !InstalledVersions::isInstalled($name)
                || InstalledVersions::getPrettyVersion($name) !== $package['version'] || InstalledVersions::getReference($name) !== $source['reference']) {
                throw new \RuntimeException('Target lock differs from installed public API');
            }
        }
    }
    /** @return array<array-key, mixed> */
    public static function corrupt(mixed $lock, bool $reference): array
    {
        if (!is_array($lock) || !is_array($lock['packages'] ?? null)) { throw new \RuntimeException('Missing packages'); }
        $packages = $lock['packages'];
        foreach ($packages as $index => $package) {
            if (!is_array($package)) { throw new \RuntimeException('Invalid pin'); }
            if ($reference) {
                $key = isset($package['source']) ? 'source' : 'dist';
                if (!is_array($package[$key] ?? null)) { throw new \RuntimeException('Missing reference'); }
                $package[$key]['reference'] = str_repeat('0', 40);
            } else { $package['version'] = '99.0.0'; }
            $packages[$index] = $package; break;
        }
        $lock['packages'] = $packages;
        return $lock;
    }
}
