<?php

declare(strict_types=1);

namespace AgenTools\Tests\Confirmation;

use AgenTools\Confirmation\ConfirmationFailure;
use AgenTools\Confirmation\ConfirmationProtocol;
use AgenTools\TestKit\InMemoryConfirmationTokenStore;
use AgenTools\Confirmation\KeyId;
use AgenTools\Confirmation\SigningKey;
use AgenTools\TestKit\StaticTokenKeyProvider;
use AgenTools\Contracts\CommitAuthorizer;
use AgenTools\Idempotency\ArgumentsFingerprint;
use AgenTools\Tool\DescriptorDigest;
use PHPUnit\Framework\TestCase;

final class DurableReplayGateTest extends TestCase
{
    private const TENANT = 'tenant-a';

    public function testNewProtocolInstanceSharesStoreAndDeniesReplay(): void
    {
        $store = new InMemoryConfirmationTokenStore();
        $keys = new StaticTokenKeyProvider(new SigningKey(new KeyId('k1'), 'HS256', str_repeat('s', 32)));
        $first = $this->protocol($keys, $store);
        $token = $first->prepare($this->proposal(), $this->now(), self::TENANT);
        $first->commit($token->value, $this->fingerprint('prepared'), $this->digest(), 'approver-1', $this->now(), $this->authorized());

        $second = $this->protocol($keys, $store);
        $this->expectException(ConfirmationFailure::class);
        $second->commit($token->value, $this->fingerprint('prepared'), $this->digest(), 'approver-1', $this->now(), $this->authorized());
    }

    public function testAuthorizationDeniedTokenCannotBeRetriedAfterLaterGrant(): void
    {
        $store = new InMemoryConfirmationTokenStore();
        $keys = new StaticTokenKeyProvider(new SigningKey(new KeyId('k1'), 'HS256', str_repeat('s', 32)));
        $protocol = $this->protocol($keys, $store);
        $token = $protocol->prepare($this->proposal(), $this->now(), self::TENANT);
        $denied = new class implements CommitAuthorizer {
            public function isAllowed(string $principal, string $toolName): bool
            {
                return false;
            }
        };
        try {
            $protocol->commit($token->value, $this->fingerprint('prepared'), $this->digest(), 'approver-1', $this->now(), $denied);
            self::fail('authorization denial expected');
        } catch (ConfirmationFailure $failure) {
            self::assertSame('agentools.permission.confirmation_authorization_denied', $failure->error->code);
        }

        try {
            $protocol->commit($token->value, $this->fingerprint('prepared'), $this->digest(), 'approver-1', $this->now(), $this->authorized());
            self::fail('spent expected');
        } catch (ConfirmationFailure $failure) {
            self::assertSame('agentools.precondition.confirmation_token_spent', $failure->error->code);
        }
    }

    public function testProtocolDoesNotDeclareProcessLocalSpentSet(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Confirmation/ConfirmationProtocol.php');
        self::assertNotFalse($source);
        self::assertStringNotContainsString('spentTokens', $source);
    }

    private function protocol(StaticTokenKeyProvider $keys, InMemoryConfirmationTokenStore $store): ConfirmationProtocol
    {
        return new ConfirmationProtocol($keys, $store, 'agentools', 'confirmation');
    }

    private function proposal(): \AgenTools\Confirmation\Proposal
    {
        return new \AgenTools\Confirmation\Proposal(
            'prop-1',
            1,
            'action-1',
            'fina_payment_send',
            'proposer-1',
            'approver-1',
            $this->fingerprint('prepared'),
            $this->digest(),
            $this->now()->modify('+1 hour'),
            ['summary' => 'Payment', 'changes' => []],
        );
    }

    private function fingerprint(string $value): ArgumentsFingerprint
    {
        return ArgumentsFingerprint::fromJcs($value);
    }

    private function digest(): DescriptorDigest
    {
        return DescriptorDigest::fromValue('sha-256:' . str_repeat('a', 64));
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-08-04T12:00:00+00:00');
    }

    private function authorized(): CommitAuthorizer
    {
        return new class implements CommitAuthorizer {
            public function isAllowed(string $principal, string $toolName): bool
            {
                return true;
            }
        };
    }
}
