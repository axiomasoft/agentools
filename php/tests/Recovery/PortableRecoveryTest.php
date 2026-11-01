<?php

declare(strict_types=1);

namespace AgenTools\Tests\Recovery;

require_once __DIR__.'/PortableRecovery.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PortableRecoveryTest extends TestCase
{
    /** @return iterable<string,array{array<string,mixed>}> */
    public static function fixtures(): iterable
    {
        foreach (PortableRecovery::cases() as $case) {
            if (!is_string($case['id'])) { throw new \RuntimeException('Invalid case ID'); }
            yield $case['id']=>[$case];
        }
    }
    /** @param array<string,mixed> $case */
    #[DataProvider('fixtures')]
    public function testPortableSemantics(array $case): void { self::assertEquals($case['expected'],PortableRecovery::run($case)); }
}
