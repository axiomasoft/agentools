<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Actions\Effects;

/** Host authenticates the operator and verifies evidence/known-outcome basis; throws on denial. */
interface ManualAuthority
{
    public function authorize(Request $request, ManualEvidence $evidence): void;
}
