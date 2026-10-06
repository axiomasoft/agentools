<?php

declare(strict_types=1);

namespace AgenTools\Tests\Persistence;

use AgenTools\Confirmation\PdoPostgresConfirmationTokenStore;
use AgenTools\Idempotency\DeduplicationPolicy;
use AgenTools\Idempotency\PdoPostgresIdempotencyStore;
use AgenTools\Persistence\PdoPostgresEventStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The PostgreSQL adapters must not reconfigure the caller's shared connection. */
final class PdoErrorModeRequirementTest extends TestCase
{
    /** @return iterable<string, array{\Closure(\PDO): object}> */
    public static function adapters(): iterable
    {
        yield 'event store' => [static fn (\PDO $pdo): object => new PdoPostgresEventStore($pdo)];
        yield 'idempotency store' => [static fn (\PDO $pdo): object => new PdoPostgresIdempotencyStore(
            $pdo,
            new DeduplicationPolicy(new \DateInterval('P1D'), new \DateInterval('PT0S'), new \DateInterval('PT0S')),
        )];
        yield 'confirmation token store' => [static fn (\PDO $pdo): object => new PdoPostgresConfirmationTokenStore($pdo)];
    }

    /** @param \Closure(\PDO): object $create */
    #[DataProvider('adapters')]
    public function testRejectsAConnectionThatDoesNotThrowAndLeavesItUntouched(\Closure $create): void
    {
        $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_SILENT]);

        try {
            $create($pdo);
            self::fail('A connection outside ERRMODE_EXCEPTION must be rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('ERRMODE_EXCEPTION', $error->getMessage());
        }
        self::assertSame(\PDO::ERRMODE_SILENT, $pdo->getAttribute(\PDO::ATTR_ERRMODE));
    }

    /** @param \Closure(\PDO): object $create */
    #[DataProvider('adapters')]
    public function testAcceptsAConnectionInExceptionMode(\Closure $create): void
    {
        $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        $create($pdo);
        self::assertSame(\PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(\PDO::ATTR_ERRMODE));
    }
}
