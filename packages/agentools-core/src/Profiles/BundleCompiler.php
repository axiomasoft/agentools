<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles;

interface BundleCompiler
{
    public function version(): DefinitionRef;

    /** Trusted code resolves and compiles the complete dependency closure, without LLM or effects. */
    public function compile(BundleManifest $manifest): CompiledProfile;
}
