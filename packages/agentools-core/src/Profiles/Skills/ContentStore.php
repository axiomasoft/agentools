<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Skills;

interface ContentStore
{
    /** Exact immutable owner/id/version/hash only. No implicit latest lookup. */
    public function load(FragmentRef $ref): Fragment;
}
