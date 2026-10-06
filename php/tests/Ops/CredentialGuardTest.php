<?php

declare(strict_types=1);

namespace AgenTools\Tests\Ops;

use AgenTools\Error\ErrorCategory;
use AgenTools\Error\ToolErrorCode;
use AgenTools\Error\ToolExecutionResult;
use AgenTools\Ops\CredentialGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CredentialGuardTest extends TestCase
{
    private const AUDIENCE = 'agentools-runtime';

    /** SEC-2; a credential issued for our resource by a trusted issuer passes validation. */
    public function testItAcceptsTokensIssuedForUs(): void
    {
        self::assertNull($this->guard()->validateAccessToken($this->credential()));
    }

    /**
     * SEC-1, SEC-2, SEC-5, SEC-6; each rejection names its reason, rule and offending field.
     *
     * @param array<string, mixed> $override
     */
    #[DataProvider('rejectedCredentials')]
    public function testItRejectsCredentialsNotIssuedForUs(array $override, string $reason, string $rule, string $field): void
    {
        $result = $this->guard()->validateAccessToken(array_merge($this->credential(), $override));

        $this->assertForeignTokenFailure($result, ['reason' => $reason, 'security_rule' => $rule, 'credential_field' => $field]);
    }

    /** @return iterable<string, array{array<string, mixed>, string, string, string}> */
    public static function rejectedCredentials(): iterable
    {
        yield 'confirmation token as access token' => [['kind' => 'confirmation_token'], 'credential_role_confusion', 'SEC-6', 'kind'];
        yield 'unknown credential kind' => [['kind' => 'api_key'], 'unsupported_credential_kind', 'SEC-1', 'kind'];
        yield 'missing kind' => [['kind' => null], 'unsupported_credential_kind', 'SEC-1', 'kind'];
        yield 'token taken from an argument' => [['source' => 'argument'], 'data_sourced_access_token', 'SEC-5', 'source'];
        yield 'token taken from model output' => [['source' => 'model_output'], 'data_sourced_access_token', 'SEC-5', 'source'];
        yield 'token taken from a tool result' => [['source' => 'tool_result'], 'data_sourced_access_token', 'SEC-5', 'source'];
        yield 'missing source' => [['source' => null], 'data_sourced_access_token', 'SEC-5', 'source'];
        yield 'foreign audience' => [['audience' => 'downstream-api'], 'foreign_access_token', 'SEC-1', 'audience'];
        yield 'missing audience' => [['audience' => null], 'foreign_access_token', 'SEC-1', 'audience'];
        yield 'untrusted issuer' => [['issuer' => 'issuer-rogue'], 'untrusted_issuer', 'SEC-2', 'issuer'];
        yield 'non-string issuer' => [['issuer' => ['issuer-trusted']], 'untrusted_issuer', 'SEC-2', 'issuer'];
        yield 'unverified integrity' => [['integrity' => 'unverified'], 'unverified_access_token', 'SEC-2', 'integrity'];
        yield 'missing integrity' => [['integrity' => null], 'unverified_access_token', 'SEC-2', 'integrity'];
    }

    /** SEC-2 item 3; there is no built-in trusted issuer, an empty declared set rejects every token. */
    public function testItHasNoImplicitlyTrustedIssuer(): void
    {
        $result = (new CredentialGuard(self::AUDIENCE, []))->validateAccessToken($this->credential());

        $this->assertForeignTokenFailure($result, ['reason' => 'untrusted_issuer', 'security_rule' => 'SEC-2', 'credential_field' => 'issuer']);
    }

    /** SEC-2 item 1; the audience is the declared identifier of our resource, not a library constant. */
    public function testItComparesAudienceWithTheDeclaredResource(): void
    {
        $result = (new CredentialGuard('agentools', ['issuer-trusted']))->validateAccessToken($this->credential());

        $this->assertForeignTokenFailure($result, ['reason' => 'foreign_access_token', 'security_rule' => 'SEC-1', 'credential_field' => 'audience']);
    }

    public function testItRejectsAnEmptyResourceAudience(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CredentialGuard('', ['issuer-trusted']);
    }

    public function testItRejectsAnEmptyTrustedIssuer(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CredentialGuard(self::AUDIENCE, ['issuer-trusted', '']);
    }

    /** SEC-5; tokens smuggled through arguments are rejected. */
    public function testItRejectsTokensEmbeddedInArguments(): void
    {
        $result = $this->guard()->rejectTokensInArguments([
            'token' => ['kind' => 'access_token', 'audience' => self::AUDIENCE],
        ]);

        $this->assertForeignTokenFailure($result, ['reason' => 'data_sourced_access_token', 'security_rule' => 'SEC-5', 'argument_pointer' => '/token']);
    }

    /** SEC-5; nesting inside maps and lists does not hide a smuggled token. */
    public function testItRejectsTokensNestedDeepInArguments(): void
    {
        $result = $this->guard()->rejectTokensInArguments([
            'payload' => ['items' => [['note' => 'x'], ['auth' => ['kind' => 'access_token']]]],
        ]);

        $this->assertForeignTokenFailure($result, ['reason' => 'data_sourced_access_token', 'security_rule' => 'SEC-5', 'argument_pointer' => '/payload/items/1/auth']);
    }

    /** SEC-5; a token passed as the whole argument object is rejected too. */
    public function testItRejectsATokenAsTheArgumentObject(): void
    {
        $result = $this->guard()->rejectTokensInArguments(['kind' => 'access_token']);

        $this->assertForeignTokenFailure($result, ['reason' => 'data_sourced_access_token', 'security_rule' => 'SEC-5', 'argument_pointer' => '']);
    }

    /** RFC 6901 escaping keeps the pointer unambiguous for keys containing "/" and "~". */
    public function testItEscapesArgumentPointerTokens(): void
    {
        $result = $this->guard()->rejectTokensInArguments(['a/b' => ['c~d' => ['kind' => 'access_token']]]);

        self::assertSame('/a~1b/c~0d', $result?->error?->context['argument_pointer'] ?? null);
    }

    /** SEC-5; structures deeper than the inspection limit fail closed. */
    public function testItRejectsArgumentsNestedBeyondTheInspectionLimit(): void
    {
        $arguments = ['leaf' => 'value'];
        for ($i = 0; $i < 64; ++$i) {
            $arguments = ['n' => $arguments];
        }

        $result = $this->guard()->rejectTokensInArguments($arguments);

        self::assertSame('argument_depth_exceeded', $result?->error?->context['reason'] ?? null);
        self::assertSame(str_repeat('/n', 64), $result->error->context['argument_pointer'] ?? null);
    }

    public function testItAcceptsArgumentsAtTheInspectionLimit(): void
    {
        $arguments = ['leaf' => 'value'];
        for ($i = 0; $i < 63; ++$i) {
            $arguments = ['n' => $arguments];
        }

        self::assertNull($this->guard()->rejectTokensInArguments($arguments));
    }

    public function testItAcceptsArgumentsWithoutTokens(): void
    {
        self::assertNull($this->guard()->rejectTokensInArguments([
            'kind' => 'invoice',
            'lines' => [['kind' => 'confirmation'], ['amount' => 10]],
        ]));
    }

    /** SEC-6; confirmation tokens cannot be used as access tokens. */
    public function testItRejectsConfirmationTokenAsAccessToken(): void
    {
        $result = $this->guard()->rejectConfirmationTokenAsAccess('confirmation_token');

        $this->assertForeignTokenFailure($result, ['reason' => 'credential_role_confusion', 'security_rule' => 'SEC-6']);
        self::assertNull($this->guard()->rejectConfirmationTokenAsAccess('access_token'));
    }

    private function guard(): CredentialGuard
    {
        return new CredentialGuard(self::AUDIENCE, ['issuer-trusted']);
    }

    /** @return array<string, mixed> */
    private function credential(): array
    {
        return [
            'kind' => 'access_token',
            'source' => 'trusted_credential',
            'issuer' => 'issuer-trusted',
            'audience' => self::AUDIENCE,
            'integrity' => 'verified',
            'principal_id' => 'user-7',
            'scopes' => ['demo.read'],
        ];
    }

    /** @param array<string, string> $context */
    private function assertForeignTokenFailure(?ToolExecutionResult $result, array $context): void
    {
        self::assertNotNull($result);
        self::assertTrue($result->isError());
        self::assertNotNull($result->error);
        self::assertSame(ToolErrorCode::ForeignAccessToken->value, $result->error->code);
        self::assertSame(ErrorCategory::PERMISSION, $result->error->category);
        self::assertFalse($result->error->retryable);
        self::assertSame($context, $result->error->context);
    }
}
