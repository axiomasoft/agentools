<?php

declare(strict_types=1);

namespace AgenTools\Conformance;

use RuntimeException;

/** Адаптер нарушил опубликованный JSONL-протокол: код возврата раннера 2. */
final class ProtocolViolation extends RuntimeException
{
}
