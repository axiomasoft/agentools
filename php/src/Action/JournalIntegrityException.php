<?php

declare(strict_types=1);

namespace AgenTools\Action;

/** ALC-37: a cryptographic journal-chain failure is distinct from a version conflict. */
final class JournalIntegrityException extends LifecycleException
{
}
