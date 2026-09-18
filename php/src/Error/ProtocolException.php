<?php

declare(strict_types=1);

namespace AgenTools\Error;

/** Transport/protocol failures are deliberately distinct from tool-result errors. */
final class ProtocolException extends \RuntimeException
{
}
