<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Plugins;

/** Closed capability-free result: no replacement pipeline, tool catalog, SQL, credentials or callback. */
final readonly class Contribution
{
    /** @param list<Fragment> $fragments */
    public function __construct(public array $fragments=[])
    { self::validate($fragments); }
    private static function validate(mixed $fragments): void
    {
        if (!is_array($fragments) || !array_is_list($fragments) || count($fragments)>32) { throw new \InvalidArgumentException('Bounded fragment list required'); }
        foreach ($fragments as $fragment) { if (!$fragment instanceof Fragment) { throw new \InvalidArgumentException('Typed fragments required'); } }
    }
}
