<?php

declare(strict_types=1);

namespace AgenTools\Tests\Confirmation;

use AgenTools\Confirmation\ConfirmationFailure;
use AgenTools\Confirmation\PdoPostgresConfirmationTokenStore;
use AgenTools\Confirmation\TokenConsumeAdmission;
use AgenTools\Tests\Support\PostgresRequirement;
use PHPUnit\Framework\TestCase;

final class PdoPostgresConfirmationTokenStoreTest extends TestCase
{
    private static ?\PDO $pdo = null;

    public static function setUpBeforeClass(): void
    {
        $dsn = getenv('AGENTOOLS_TEST_PG_DSN') ?: 'pgsql:host=127.0.0.1;port=55432;dbname=agentools_test';
        try {
            self::$pdo = new \PDO($dsn, 'agentools', 'agentools-test', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        } catch (\PDOException) {
            self::$pdo = null;
        }
        if (self::$pdo === null) {
            return;
        }
        $sql = file_get_contents(dirname(__DIR__, 3) . '/fixtures/hardening/sql/confirmation-consumed-tokens.v1.sql');
        self::assertIsString($sql);
        self::$pdo->exec('DROP TABLE IF EXISTS confirmation_consumed_tokens');
        self::$pdo->exec($sql);
    }

    protected function setUp(): void
    {
        if (self::$pdo === null) {
            PostgresRequirement::unavailable();
        }
        self::$pdo->exec('TRUNCATE confirmation_consumed_tokens');
    }

    public function testConcurrentConsumeYieldsOneWinner(): void
    {
        $pdoA = self::connect();
        $pdoB = self::connect();
        $storeA = new PdoPostgresConfirmationTokenStore($pdoA);
        $storeB = new PdoPostgresConfirmationTokenStore($pdoB);
        $now = new \DateTimeImmutable('2026-09-20T10:00:00+00:00');
        $expires = new \DateTimeImmutable('2026-09-20T12:00:00+00:00');
        $digest = hash('sha256', 'token-a');

        $winner = 0;
        try {
            $storeA->consume('tenant-a', 'jti-1', $digest, 'confirmation', $expires, $now);
            $winner++;
        } catch (ConfirmationFailure) {
        }
        try {
            $storeB->consume('tenant-a', 'jti-1', $digest, 'confirmation', $expires, $now);
            $winner++;
        } catch (ConfirmationFailure $failure) {
            self::assertSame('agentools.precondition.confirmation_token_spent', $failure->error->code);
        }
        self::assertSame(1, $winner);
    }

    public function testRestartDeniesPreviouslyPresentedToken(): void
    {
        $now = new \DateTimeImmutable('2026-09-20T10:00:00+00:00');
        $expires = new \DateTimeImmutable('2026-09-20T12:00:00+00:00');
        $digest = hash('sha256', 'token-a');
        (new PdoPostgresConfirmationTokenStore(self::connect()))->consume('tenant-a', 'jti-1', $digest, 'confirmation', $expires, $now);
        $restarted = new PdoPostgresConfirmationTokenStore(self::connect());
        $this->expectException(ConfirmationFailure::class);
        $restarted->consume('tenant-a', 'jti-1', $digest, 'confirmation', $expires, $now);
    }

    public function testCollisionDoesNotOverwriteDigest(): void
    {
        $now = new \DateTimeImmutable('2026-09-20T10:00:00+00:00');
        $expires = new \DateTimeImmutable('2026-09-20T12:00:00+00:00');
        $store = new PdoPostgresConfirmationTokenStore(self::connect());
        self::assertSame(TokenConsumeAdmission::Accepted, $store->consume('tenant-a', 'jti-1', hash('sha256', 'token-a'), 'confirmation', $expires, $now));
        try {
            $store->consume('tenant-a', 'jti-1', hash('sha256', 'token-b'), 'confirmation', $expires, $now);
            self::fail('collision expected');
        } catch (ConfirmationFailure $failure) {
            self::assertSame('agentools.conflict.confirmation_token_collision', $failure->error->code);
        }
    }

    public function testCleanupDoesNotDeleteUnexpiredRows(): void
    {
        $now = new \DateTimeImmutable('2026-09-20T10:00:00+00:00');
        $expires = new \DateTimeImmutable('2026-09-20T12:00:00+00:00');
        $store = new PdoPostgresConfirmationTokenStore(self::connect());
        $store->consume('tenant-a', 'jti-1', hash('sha256', 'token-a'), 'confirmation', $expires, $now);
        self::assertSame(0, $store->cleanup($now, new \DateInterval('PT1H')));
        $pdo = self::$pdo;
        self::assertInstanceOf(\PDO::class, $pdo);
        $statement = $pdo->query("SELECT COUNT(*) FROM confirmation_consumed_tokens");
        self::assertInstanceOf(\PDOStatement::class, $statement);
        self::assertSame(1, (int) $statement->fetchColumn());
    }

    public function testCalendarDayRetentionDoesNotCollapseToZeroSeconds(): void
    {
        $now = new \DateTimeImmutable('2026-09-20T10:00:00+00:00');
        $expires = new \DateTimeImmutable('2026-09-20T12:00:00+00:00');
        $store = new PdoPostgresConfirmationTokenStore(self::connect());
        $store->consume('tenant-a', 'jti-1', hash('sha256', 'token-a'), 'confirmation', $expires, $now);

        self::assertSame(
            0,
            $store->cleanup(new \DateTimeImmutable('2026-09-21T01:00:00+00:00'), new \DateInterval('P1D')),
        );
        self::assertSame(
            1,
            $store->cleanup(new \DateTimeImmutable('2026-09-21T13:00:00+00:00'), new \DateInterval('P1D')),
        );
    }

    public function testCleanupRejectsRetentionBelowMaximumClockSkew(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new PdoPostgresConfirmationTokenStore(self::connect()))->cleanup(
            new \DateTimeImmutable('2026-09-20T10:00:00+00:00'),
            new \DateInterval('PT299S'),
        );
    }

    private static function connect(): \PDO
    {
        $dsn = getenv('AGENTOOLS_TEST_PG_DSN') ?: 'pgsql:host=127.0.0.1;port=55432;dbname=agentools_test';
        return new \PDO($dsn, 'agentools', 'agentools-test', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }
}
