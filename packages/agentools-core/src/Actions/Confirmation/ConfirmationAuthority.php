<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Actions\Confirmation;

use AgenToolsPlatform\Core\Actions\ActionSubmission;

/** Host-owned presentation and authenticated consent source, never a model callback. */
interface ConfirmationAuthority
{
    /** Render the exact saved input; return a JSON object with summary and changes. */
    public function presentation(ActionSubmission $submission): string;

    /** Null for missing/unbound/transcript-only/model-generated consent. */
    public function decision(Preview $preview): ?Decision;
}
