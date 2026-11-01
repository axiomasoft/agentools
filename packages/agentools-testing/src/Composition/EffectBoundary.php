<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Testing\Composition;

use AgenToolsPlatform\Core\Actions\Effects\Request;

/** Consumer must verify current confirmation, committed intent, exact hash and epoch BEFORE owner IO.
 * Contract-only attestations cannot qualify PostgreSQL/durability. PG consumers use actual ledger rows.
 */
interface EffectBoundary { public function assertAccepted(Request $request): void; public function reset(): void; }
