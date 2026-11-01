<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Actions\Effects;

/** Owner enforces current permissions and key/hash atomically, including concurrent/stale callers.
 * ReadBeforeWrite promises authoritative absence, including exclusion of an older in-flight send.
 * A local fence alone never qualifies an arbitrary API for automatic replay.
 */
interface OwnerPort
{
    public function strategy(): Strategy;
    public function namespace(): string;
    public function execute(Request $request): LookupResult;
    public function lookup(Request $request): LookupResult;
}
