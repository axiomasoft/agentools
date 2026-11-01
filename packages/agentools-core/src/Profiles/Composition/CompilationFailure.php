<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Composition;

/** Publisher records failed validation; preview/resolve retains the complete field-level conflict. */
final class CompilationFailure extends \InvalidArgumentException
{
    public function __construct(public readonly ProfileConflict $conflict) { parent::__construct($conflict->code); }
}
