<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Plugins;

/** Safe decision codes only; no raw plugin exception message or prompt content. */
final readonly class HookResult
{
    /** @param list<array{plugin:string,phase:string,status:string,code:string}> $decisions */
    public function __construct(public Contribution $contribution,public array $decisions) {}
}
