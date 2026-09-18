<?php

declare(strict_types=1);

namespace AgenTools\Conformance;

use RuntimeException;

/** Адаптер недоступен или не отвечает: это наблюдаемый отказ SUT. */
final class SutFailure extends RuntimeException
{
}
