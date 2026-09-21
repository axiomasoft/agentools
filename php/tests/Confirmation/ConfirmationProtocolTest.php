<?php

declare(strict_types=1);

namespace AgenTools\Tests\Confirmation;

use AgenTools\Confirmation\ConfirmationFailure;
use AgenTools\Confirmation\ConfirmationJournalSupport;
use AgenTools\Confirmation\ConfirmationProtocol;
use AgenTools\Confirmation\DecisionAudit;
use AgenTools\TestKit\InMemoryConfirmationTokenStore;
use AgenTools\Confirmation\KeyId;
use AgenTools\Confirmation\Proposal;
use AgenTools\Confirmation\SigningKey;
use AgenTools\TestKit\StaticTokenKeyProvider;
use AgenTools\Contracts\CommitAuthorizer;
use AgenTools\Idempotency\ArgumentsFingerprint;
use AgenTools\Tool\DescriptorDigest;
use PHPUnit\Framework\TestCase;

final class ConfirmationProtocolTest extends TestCase
{
    private const TENANT = 'test-tenant';

    /** CNF-18; arguments shown at prepare cannot be swapped at commit. */
    public function testItRejectsChangedArguments(): void
    {
        $protocol = $this->protocol();
        $token = $protocol->prepare($this->proposal(), $this->now(), self::TENANT);

        $this->assertFailure('agentools.precondition.confirmation_args_mismatch', fn (): \AgenTools\Confirmation\ConfirmationToken => $protocol->commit($token->value, $this->fingerprint('changed'), $this->digest(), 'approver-1', $this->now(), $this->authorized()));
    }

    /** CNF-19; expiry is a structured tool-execution error. */
    public function testItRejectsExpiredTokens(): void
    {
        $protocol = $this->protocol();
        $token = $protocol->prepare($this->proposal('+1 hour'), $this->now(), self::TENANT);

        $this->assertFailure('agentools.precondition.confirmation_expired', fn (): \AgenTools\Confirmation\ConfirmationToken => $protocol->commit($token->value, $this->fingerprint('prepared'), $this->digest(), 'approver-1', $this->now()->modify('+2 hours'), $this->authorized()));
    }

    /** CNF-20; the same token cannot execute the action twice. */
    public function testItRejectsAPresentedToken(): void
    {
        $protocol = $this->protocol();
        $token = $protocol->prepare($this->proposal(), $this->now(), self::TENANT);
        $protocol->commit($token->value, $this->fingerprint('prepared'), $this->digest(), 'approver-1', $this->now(), $this->authorized());

        $this->assertFailure('agentools.precondition.confirmation_token_spent', fn (): \AgenTools\Confirmation\ConfirmationToken => $protocol->commit($token->value, $this->fingerprint('prepared'), $this->digest(), 'approver-1', $this->now(), $this->authorized()));
    }

    /** CNF-21; a token is covered by the only principal allowed to commit. */
    public function testItRejectsAForeignPrincipal(): void
    {
        $protocol = $this->protocol();
        $token = $protocol->prepare($this->proposal(), $this->now(), self::TENANT);

        $this->assertFailure('agentools.permission.confirmation_principal_mismatch', fn (): \AgenTools\Confirmation\ConfirmationToken => $protocol->commit($token->value, $this->fingerprint('prepared'), $this->digest(), 'other-user', $this->now(), $this->authorized()));
    }

    /** CNF-33, CNF-34; dual control compares principal ids, not roles. */
    public function testItRejectsTheProposerInDualControlMode(): void
    {
        $protocol = $this->protocol();
        $proposal = $this->proposal(principal: 'proposer-1', proposerId: 'proposer-1');
        $token = $protocol->prepare($proposal, $this->now(), self::TENANT);

        $this->expectException(\LogicException::class);
        $protocol->commit($token->value, $this->fingerprint('prepared'), $this->digest(), 'proposer-1', $this->now(), $this->authorized(), 'proposer-1', true);
    }

    /** CNF-35, CNF-36; permission is evaluated again at commit, not cached from prepare. */
    public function testItReauthorizesAtCommit(): void
    {
        $protocol = $this->protocol();
        $token = $protocol->prepare($this->proposal(), $this->now(), self::TENANT);
        $denied = new class implements CommitAuthorizer {
            public function isAllowed(string $principal, string $toolName): bool
            {
                return false;
            }
        };

        $this->assertFailure('agentools.permission.confirmation_authorization_denied', fn (): \AgenTools\Confirmation\ConfirmationToken => $protocol->commit($token->value, $this->fingerprint('prepared'), $this->digest(), 'approver-1', $this->now(), $denied));
    }

    /** CNF-29; a proposal without a future expires_at deadline cannot receive a token. */
    public function testItRejectsProposalWithoutValidTtlOnPrepare(): void
    {
        $protocol = $this->protocol();

        $this->expectException(\InvalidArgumentException::class);
        $protocol->prepare($this->proposal('-1 minute'), $this->now(), self::TENANT);
    }

    public function testItRejectsProposalAboveDefaultLifetime(): void
    {
        $protocol = $this->protocol();

        $this->expectException(\InvalidArgumentException::class);
        $protocol->prepare($this->proposal('+73 hours'), $this->now(), self::TENANT);
    }

    /** CNF-26, CNF-28; an edit supersedes the immutable proposal instead of changing it. */
    public function testAnEditSupersedesThePriorProposal(): void
    {
        $original = $this->proposal();
        $successor = $this->editedProposal();
        $payloads = ConfirmationJournalSupport::editJournalPayloads($original, $successor);
        $token = $this->protocol()->prepare($successor, $this->now(), self::TENANT);

        self::assertSame('pending', $payloads[0]['state']);
        self::assertSame('superseded', $payloads[1]['state']);
        self::assertSame('prop-2', $payloads[1]['superseded_by']);
        self::assertSame(2, $payloads[2]['proposal_version']);
        self::assertNotSame($payloads[0]['proposal_id'], $payloads[2]['proposal_id']);
        self::assertNotEmpty($token->value);
    }

    /** CNF-37, CNF-38, CNF-39; audit payload carries the same fingerprint at decision and commit. */
    public function testDecisionAuditCarriesMatchedApprovalFingerprints(): void
    {
        $fingerprint = $this->fingerprint('prepared');
        $audit = new DecisionAudit('prop-1', 'opaque', 'approve', 'approver-1', 'finance_approver', $this->now(), '', $fingerprint, $fingerprint);

        self::assertSame($fingerprint->value, $audit->toPayload()['args_hash_at_decision']);
        self::assertSame($fingerprint->value, $audit->toPayload()['args_hash_at_commit']);
    }

    public function testPublicTimeCarriersAreNormalizedToUtc(): void
    {
        $localNow = new \DateTimeImmutable('2026-08-04T15:00:00+03:00');
        $proposal = new Proposal('prop-1', 1, 'action-1', 'fina_payment_send', 'proposer-1', 'approver-1', $this->fingerprint('prepared'), $this->digest(), $localNow->modify('+1 hour'), ['summary' => 'Payment', 'changes' => []]);
        $key = new SigningKey(new KeyId('k1'), 'HS256', str_repeat('s', 32));
        $keys = new StaticTokenKeyProvider($key);
        $token = (new ConfirmationProtocol($keys, new InMemoryConfirmationTokenStore(), 'agentools', 'confirmation'))
            ->prepare($proposal, $localNow, self::TENANT);
        $envelope = \AgenTools\Confirmation\TokenEnvelope::parse($token->value, $keys, $localNow, 'agentools', 'confirmation');
        $audit = new DecisionAudit('prop-1', 'opaque', 'approve', 'approver-1', 'finance_approver', $localNow, '', $this->fingerprint('prepared'), $this->fingerprint('prepared'));

        self::assertSame('2026-08-04T13:00:00+00:00', $envelope->claims['expires_at']);
        self::assertSame('2026-08-04T12:00:00+00:00', $envelope->claims['nbf']);
        self::assertSame('2026-08-04T12:00:00+00:00', $audit->toPayload()['decided_at']);
        self::assertSame('2026-08-04T13:00:00+00:00', ConfirmationJournalSupport::requestedPayload($proposal)['deadline']);
    }

    /** CNF-38; mismatched decision and commit hashes deny commit even when the token matches. */
    public function testItRejectsCommitWhenDecisionAuditHashesMismatch(): void
    {
        $protocol = $this->protocol();
        $token = $protocol->prepare($this->proposal(), $this->now(), self::TENANT);
        $decisionHash = $this->fingerprint('prepared');
        $commitHash = $this->fingerprint('changed');
        $audit = new DecisionAudit('prop-1', $token->value, 'approve', 'approver-1', 'finance_approver', $this->now(), '', $decisionHash, $commitHash);

        $this->assertFailure('agentools.precondition.confirmation_args_mismatch', fn (): \AgenTools\Confirmation\ConfirmationToken => $protocol->commit($token->value, $this->fingerprint('prepared'), $this->digest(), 'approver-1', $this->now(), $this->authorized(), null, false, $audit));
    }

    /** @param \Closure(): \AgenTools\Confirmation\ConfirmationToken $operation */
    private function assertFailure(string $code, \Closure $operation): void
    {
        try {
            $operation();
            self::fail('Confirmation failure was not raised.');
        } catch (ConfirmationFailure $exception) {
            self::assertSame($code, $exception->error->code);
        }
    }

    private function protocol(): ConfirmationProtocol
    {
        $key = new SigningKey(new KeyId('k1'), 'HS256', str_repeat('s', 32));

        return new ConfirmationProtocol(
            new StaticTokenKeyProvider($key),
            new InMemoryConfirmationTokenStore(),
            'agentools',
            'confirmation',
        );
    }

    private function proposal(string $expiry = '+1 hour', string $principal = 'approver-1', string $proposerId = 'proposer-1'): Proposal
    {
        return new Proposal('prop-1', 1, 'action-1', 'fina_payment_send', $proposerId, $principal, $this->fingerprint('prepared'), $this->digest(), $this->now()->modify($expiry), ['summary' => 'Payment', 'changes' => []]);
    }

    private function editedProposal(): Proposal
    {
        return new Proposal('prop-2', 2, 'action-1', 'fina_payment_send', 'proposer-1', 'approver-1', $this->fingerprint('edited'), $this->digest(), $this->now()->modify('+1 hour'), ['summary' => 'Payment edited', 'changes' => []]);
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
