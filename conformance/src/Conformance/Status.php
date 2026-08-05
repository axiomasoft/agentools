<?php

declare(strict_types=1);

namespace AgenTools\Conformance;

/** Закрытый словарь исходов: тихого skip в нём намеренно нет. */
enum Status: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case Warn = 'warn';
    case NotTestable = 'not-testable';
    case BaselineExpected = 'baseline-expected';
}
