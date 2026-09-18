<?php

declare(strict_types=1);

namespace AgenTools\Tests\Ops;

use AgenTools\Error\ToolErrorCode;
use AgenTools\Ops\CredentialGuard;
use PHPUnit\Framework\TestCase;

final class CredentialGuardTest extends TestCase
{
    /** SEC-1, SEC-3; foreign audience tokens are rejected with a distinct permission code. */
    public function testItRejectsForeignAudienceTokens(): void
    {
        $result = (new CredentialGuard())->validateAccessToken([
            'kind' => 'access_token',
            'audience' => 'downstream-api',
            'issuer' => 'issuer-trusted',
            'integrity' => 'verified',
        ]);

        self::assertNotNull($result);
        self::assertTrue($result->isError());
        self::assertSame(ToolErrorCode::ForeignAccessToken->value, $result->error?->code);
        self::assertSame('SEC-3', $result->error?->context['security_rule'] ?? null);
        self::assertSame('foreign_access_token', $result->error?->context['reason'] ?? null);
    }

    /** SEC-2; trusted audience passes validation. */
    public function testItAcceptsTokensIssuedForUs(): void
    {
        $result = (new CredentialGuard())->validateAccessToken([
            'kind' => 'access_token',
            'audience' => 'agentools',
            'issuer' => 'issuer-trusted',
            'integrity' => 'verified',
        ]);

        self::assertNull($result);
    }

    /** SEC-5; tokens smuggled through arguments are rejected. */
    public function testItRejectsTokensEmbeddedInArguments(): void
    {
        $result = (new CredentialGuard())->rejectTokensInArguments([
            'token' => ['kind' => 'access_token', 'audience' => 'agentools'],
        ]);

        self::assertNotNull($result);
        self::assertSame(ToolErrorCode::ForeignAccessToken->value, $result->error?->code);
    }

    /** SEC-6; confirmation tokens cannot be used as access tokens. */
    public function testItRejectsConfirmationTokenAsAccessToken(): void
    {
        $result = (new CredentialGuard())->rejectConfirmationTokenAsAccess('confirmation_token');

        self::assertNotNull($result);
        self::assertSame(ToolErrorCode::ForeignAccessToken->value, $result->error?->code);
    }
}
