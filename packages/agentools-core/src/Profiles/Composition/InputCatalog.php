<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Composition;

use AgenToolsPlatform\Core\Profiles\BundleManifest;

interface InputCatalog
{
    /** Load exact pinned typed documents, never latest or candidate-provided effective prompt strings. */
    public function load(BundleManifest $manifest): CompilationInput;
}
