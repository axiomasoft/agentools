<?php

declare(strict_types=1);

namespace AgenTools\Tests\Confirmation;

use AgenTools\Confirmation\ConfirmationFailure;
use AgenTools\Confirmation\ConfirmationProtocol;
use AgenTools\Confirmation\DecisionAudit;
use AgenTools\Confirmation\HmacTokenSigner;
use AgenTools\Confirmation\Proposal;
use AgenTools\Contracts\CommitAuthorizer;
use AgenTools\Idempotency\ArgumentsFingerprint;
use AgenTools\Tool\DescriptorDigest;
use PHPUnit\Framework\TestCase;

final class ConfirmationProtocolTest extends TestCase
{
    /** CNF-18; arguments shown at prepare cannot be swapped at commit. */
    public function testItRejectsChangedArguments(): void
    {
        $protocol = $this->protocol();
        $token = $protocol->prepare($this->proposal());

        $this->assertFailure('agentools.precondition.confirmation_args_mismatch', fn (): \AgenTools\Confirmation\ConfirmationToken => $protocol->commit($token->value, $this->fingerprint('changed'), $this->digest(), 'approver-1', $this->now(), $this->authorized()));
    }

    /** CNF-19; expiry is a structured tool-execution error. */
    public function testItRejectsExpiredTokens(): void
    {
        $protocol = $this->protocol();
        $token = $protocol->prepare($this->proposal('-1 minute'));

        $this->assertFailure('agentools.precondition.confirmation_expired', fn (): \AgenTools\Confirmation\ConfirmationToken => $protocol->commit($token->value, $this->fingerprint('prepared'), $this->digest(), 'approver-1', $this->now(), $this->authorized()));
    }

    /** CNF-20; the same token cannot execute the action twice. */
    public function testItRejectsAPresentedToken(): void
    {
        $protocol = $this->protocol();
        $token = $protocol->prepare($this->proposal());
        $protocol->commit($token->value, $this->fingerprint('prepared'), $this->digest(), 'approver-1', $this->now(), $this->authorized());

        $this->assertFailure('agentools.precondition.confirmation_token_spent', fn (): \AgenTools\Confirmation\ConfirmationToken => $protocol->commit($token->value, $this->fingerprint('prepared'), $this->digest(), 'approver-1', $this->now(), $this->authorized()));
    }

    /** CNF-21; a token is covered by the only principal allowed to commit. */
    public function testItRejectsAForeignPrincipal(): void
    {
        $protocol = $this->protocol();
        $token = $protocol->prepare($this->proposal());

        $this->assertFailure('agentools.permission.confirmation_principal_mismatch', fn (): \AgenTools\Confirmation\ConfirmationToken => $protocol->commit($token->value, $this->fingerprint('prepared'), $this->digest(), 'other-user', $this->now(), $this->authorized()));
    }

    /** CNF-33, CNF-34; dual control compares principal ids, not roles. */
    public function testItRejectsTheProposerInDualControlMode(): void
    {
        $protocol = $this->protocol();
        $proposal = $this->proposal(principal: 'proposer-1', proposerId: 'proposer-1');
        $token = $protocol->prepare($proposal);

        $this->expectException(\LogicException::class);
        $protocol->commit($token->value, $this->fingerprint('prepared'), $this->digest(), 'proposer-1', $this->now(), $this->authorized(), 'proposer-1', true);
    }

    /** CNF-35, CNF-36; permission is evaluated again at commit, not cached from prepare. */
    public function testItReauthorizesAtCommit(): void
    {
        $protocol = $this->protocol();
        $token = $protocol->prepare($this->proposal());
        $denied = new class implements CommitAuthorizer {
            public function isAllowed(string $principal, string $toolName): bool
            {
                return false;
            }
        };

        $this->expectException(\LogicException::class);
        $protocol->commit($token->value, $this->fingerprint('prepared'), $this->digest(), 'approver-1', $this->now(), $denied);
    }

    /** CNF-26, CNF-28; an edit supersedes the immutable proposal instead of changing it. */
    public function testAnEditSupersedesThePriorProposal(): void
    {
        $original = $this->proposal();
        $superseded = $original->supersede('prop-2');

        self::assertSame('pending', $original->state->value);
        self::assertSame('superseded', $superseded->state->value);
        self::assertSame('prop-2', $superseded->supersededBy);
    }

    /** CNF-37, CNF-38, CNF-39; audit payload carries the same fingerprint at decision and commit. */
    public function testDecisionAuditCarriesMatchedApprovalFingerprints(): void
    {
        $fingerprint = $this->fingerprint('prepared');
        $audit = new DecisionAudit('prop-1', 'opaque', 'approve', 'approver-1', 'finance_approver', $this->now(), '', $fingerprint, $fingerprint);

        self::assertSame($fingerprint->value, $audit->toPayload()['args_hash_at_decision']);
        self::assertSame($fingerprint->value, $audit->toPayload()['args_hash_at_commit']);
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
        return new ConfirmationProtocol(new HmacTokenSigner('test-secret'));
    }

    private function proposal(string $expiry = '+1 hour', string $principal = 'approver-1', string $proposerId = 'proposer-1'): Proposal
    {
        return new Proposal('prop-1', 1, 'action-1', 'fina_payment_send', $proposerId, $principal, $this->fingerprint('prepared'), $this->digest(), $this->now()->modify($expiry), ['summary' => 'Payment', 'changes' => []]);
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
