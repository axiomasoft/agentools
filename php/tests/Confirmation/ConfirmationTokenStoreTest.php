<?php

declare(strict_types=1);

namespace AgenTools\Tests\Confirmation;

use AgenTools\Confirmation\ConfirmationFailure;
use AgenTools\TestKit\InMemoryConfirmationTokenStore;
use AgenTools\Confirmation\TokenConsumeAdmission;
use PHPUnit\Framework\TestCase;

final class ConfirmationTokenStoreTest extends TestCase
{
    public function testHappyPathConsumeAndSpent(): void
    {
        $store = new InMemoryConfirmationTokenStore();
        $now = new \DateTimeImmutable('2026-09-20T10:00:00+00:00');
        $expires = new \DateTimeImmutable('2026-09-20T12:00:00+00:00');
        self::assertSame(TokenConsumeAdmission::Accepted, $store->consume('tenant-a', 'jti-1', hash('sha256', 'token-a'), 'confirmation', $expires, $now));

        $this->expectException(ConfirmationFailure::class);
        $store->consume('tenant-a', 'jti-1', hash('sha256', 'token-a'), 'confirmation', $expires, $now);
    }

    public function testDigestJtiCollision(): void
    {
        $store = new InMemoryConfirmationTokenStore();
        $now = new \DateTimeImmutable('2026-09-20T10:00:00+00:00');
        $expires = new \DateTimeImmutable('2026-09-20T12:00:00+00:00');
        $store->consume('tenant-a', 'jti-1', hash('sha256', 'token-a'), 'confirmation', $expires, $now);

        try {
            $store->consume('tenant-a', 'jti-1', hash('sha256', 'token-b'), 'confirmation', $expires, $now);
            self::fail('collision expected');
        } catch (ConfirmationFailure $failure) {
            self::assertSame('agentools.conflict.confirmation_token_collision', $failure->error->code);
        }
    }

    public function testCleanupKeepsUnexpiredRows(): void
    {
        $store = new InMemoryConfirmationTokenStore();
        $now = new \DateTimeImmutable('2026-09-20T10:00:00+00:00');
        $expires = new \DateTimeImmutable('2026-09-20T12:00:00+00:00');
        $store->consume('tenant-a', 'jti-1', hash('sha256', 'token-a'), 'confirmation', $expires, $now);
        self::assertSame(0, $store->cleanup($now, new \DateInterval('PT1H')));
    }

    public function testCleanupRejectsRetentionBelowMaximumClockSkew(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new InMemoryConfirmationTokenStore())->cleanup(
            new \DateTimeImmutable('2026-09-20T10:00:00+00:00'),
            new \DateInterval('PT299S'),
        );
    }

    public function testInMemoryStoreDoesNotPersistAcrossInstances(): void
    {
        $now = new \DateTimeImmutable('2026-09-20T10:00:00+00:00');
        $expires = new \DateTimeImmutable('2026-09-20T12:00:00+00:00');
        $first = new InMemoryConfirmationTokenStore();
        $first->consume('tenant-a', 'jti-1', hash('sha256', 'token-a'), 'confirmation', $expires, $now);
        $second = new InMemoryConfirmationTokenStore();
        self::assertSame(
            TokenConsumeAdmission::Accepted,
            $second->consume('tenant-a', 'jti-1', hash('sha256', 'token-a'), 'confirmation', $expires, $now),
        );
    }
}
