<?php

declare(strict_types=1);

namespace AgenTools\Tests\Confirmation;

use AgenTools\Confirmation\ConfirmationFailure;
use AgenTools\Confirmation\KeyId;
use AgenTools\Confirmation\KeyState;
use AgenTools\Confirmation\SigningKey;
use AgenTools\Confirmation\TokenEnvelope;
use AgenTools\Confirmation\TokenKeyProvider;
use AgenTools\Confirmation\TokenPurpose;
use AgenTools\Confirmation\VerificationKey;
use PHPUnit\Framework\TestCase;

final class TokenEnvelopeTest extends TestCase
{
    private const ISS = 'agentools';
    private const AUD = 'confirmation';
    private const CANONICAL = '{"action_id":"act-1","alg":"HS256","args_fingerprint":"fp","aud":"confirmation","descriptor_digest":"dd","expires_at":"2026-09-20T12:00:00+00:00","iat":"2026-09-20T10:00:00+00:00","iss":"agentools","jti":"00000000000000000000000000000001","kid":"k1","nbf":"2026-09-20T10:00:00+00:00","principal":"user-a","proposal_id":"p-1","proposal_version":1,"purpose":"confirmation","tenant_id":"tenant-a","tool_name":"tool","v":1}';

    public function testManifestParseAndVerifyCases(): void
    {
        $raw = file_get_contents(dirname(__DIR__, 3) . '/fixtures/hardening/token-replay-v1.json');
        self::assertIsString($raw);
        /** @var array{cases: list<array{id: string, phase: string, scenario: string, semantic_code: string}>} $manifest */
        $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $now = new \DateTimeImmutable('2026-09-20T11:00:00+00:00');
        $keys = $this->keys();
        $seen = [];
        foreach ($manifest['cases'] as $case) {
            if (!in_array($case['phase'], ['parse', 'verify'], true)) {
                continue;
            }
            $seen[] = $case['scenario'];
            try {
                $this->exercise($case['scenario'], $keys, $now);
                self::fail($case['id'] . ' should deny');
            } catch (ConfirmationFailure $failure) {
                self::assertSame($case['semantic_code'], $failure->error->code, $case['id']);
            }
        }
        self::assertContains('oversize', $seen);
        self::assertContains('none_alg', $seen);
    }

    public function testHappyRoundTripAndCanonicalBytes(): void
    {
        $claims = $this->claims();
        self::assertSame(self::CANONICAL, TokenEnvelope::canonicalJson($claims));
        $issued = TokenEnvelope::issue($claims, $this->signing());
        $parsed = TokenEnvelope::parse($issued->value, $this->keys(), new \DateTimeImmutable('2026-09-20T11:00:00+00:00'), self::ISS, self::AUD);
        self::assertSame($issued->value, $parsed->value);
        self::assertSame('00000000000000000000000000000001', $parsed->jti);
    }

    public function testOneBitMacMutationIsRejected(): void
    {
        $issued = TokenEnvelope::issue($this->claims(), $this->signing())->value;
        $rest = substr($issued, strlen(TokenEnvelope::PREFIX));
        [$payload, $macB64] = explode('.', $rest, 2);
        $pad = str_repeat('=', (4 - strlen($macB64) % 4) % 4);
        $mac = base64_decode(strtr($macB64, '-_', '+/') . $pad, true);
        self::assertNotFalse($mac);
        $mac[0] = chr(ord($mac[0]) ^ 1);
        $mutated = TokenEnvelope::PREFIX . $payload . '.' . $this->b64($mac);
        $this->expectException(ConfirmationFailure::class);
        TokenEnvelope::parse($mutated, $this->keys(), new \DateTimeImmutable('2026-09-20T11:00:00+00:00'), self::ISS, self::AUD);
    }

    public function testVerifyOnlyCannotSign(): void
    {
        $this->expectException(ConfirmationFailure::class);
        new SigningKey(new KeyId('k1'), 'HS256', str_repeat('k', 32), KeyState::VerifyOnly);
    }

    public function testRelativeAuthenticatedTimeIsNormalizedToTokenInvalid(): void
    {
        $token = $this->issueWith(['nbf' => 'tomorrow']);

        try {
            TokenEnvelope::parse($token, $this->keys(), new \DateTimeImmutable('2026-09-20T11:00:00+00:00'), self::ISS, self::AUD);
            self::fail('Relative time must be rejected.');
        } catch (ConfirmationFailure $failure) {
            self::assertSame('agentools.validation.confirmation_token_invalid', $failure->error->code);
        }
    }

    public function testAuthenticatedLifetimeAboveHardMaximumIsRejected(): void
    {
        $token = $this->issueWith(['expires_at' => '2026-09-28T10:00:01+00:00']);

        try {
            TokenEnvelope::parse($token, $this->keys(), new \DateTimeImmutable('2026-09-20T11:00:00+00:00'), self::ISS, self::AUD);
            self::fail('Lifetime above seven days must be rejected.');
        } catch (ConfirmationFailure $failure) {
            self::assertSame('agentools.validation.confirmation_token_invalid', $failure->error->code);
        }
    }

    private function exercise(string $scenario, TokenKeyProvider $keys, \DateTimeImmutable $now): void
    {
        $token = TokenEnvelope::issue($this->claims(), $this->signing())->value;
        switch ($scenario) {
            case 'oversize':
                TokenEnvelope::parse(str_repeat('x', TokenEnvelope::MAX_RAW_BYTES + 1), $keys, $now, self::ISS, self::AUD);
                return;
            case 'extra_segment':
                TokenEnvelope::parse($token . '.extra', $keys, $now, self::ISS, self::AUD);
                return;
            case 'duplicate_json_name':
                TokenEnvelope::parse('at1.' . $this->b64('{"v":1,"v":2}') . '.' . $this->b64('sig'), $keys, $now, self::ISS, self::AUD);
                return;
            case 'unknown_version':
                TokenEnvelope::parse($this->issueWith(['v' => 2]), $keys, $now, self::ISS, self::AUD);
                return;
            case 'old_two_segment_token':
                TokenEnvelope::parse('payload.sig', $keys, $now, self::ISS, self::AUD);
                return;
            case 'extra_member':
                TokenEnvelope::parse($this->issueWith(['extra' => 'x']), $keys, $now, self::ISS, self::AUD);
                return;
            case 'nested_member':
                TokenEnvelope::parse($this->issueWith(['nested' => ['a' => 1]]), $keys, $now, self::ISS, self::AUD);
                return;
            case 'missing_required_claim':
                $claims = $this->claims();
                unset($claims['jti']);
                TokenEnvelope::parse($this->signRaw(TokenEnvelope::canonicalJson($claims)), $keys, $now, self::ISS, self::AUD);
                return;
            case 'unknown_alg':
                TokenEnvelope::parse($this->issueWith(['alg' => 'RS256']), $keys, $now, self::ISS, self::AUD);
                return;
            case 'none_alg':
                TokenEnvelope::parse($this->issueWith(['alg' => 'none']), $keys, $now, self::ISS, self::AUD);
                return;
            case 'unknown_kid':
                TokenEnvelope::parse($this->issueWith(['kid' => 'other']), $this->keys(), $now, self::ISS, self::AUD);
                return;
            case 'retired_kid':
                TokenEnvelope::parse($token, $this->keys(KeyState::Retired), $now, self::ISS, self::AUD);
                return;
            case 'compromised_kid':
                TokenEnvelope::parse($token, $this->keys(KeyState::Compromised), $now, self::ISS, self::AUD);
                return;
            case 'cross_tenant_key':
                TokenEnvelope::parse($this->issueWith(['tenant_id' => 'tenant-b']), $keys, $now, self::ISS, self::AUD);
                return;
            case 'purpose_mismatch':
                TokenEnvelope::parse($this->issueWith(['purpose' => 'event']), $keys, $now, self::ISS, self::AUD);
                return;
            case 'iss_mismatch':
                TokenEnvelope::parse($token, $keys, $now, 'other-iss', self::AUD);
                return;
            case 'aud_mismatch':
                TokenEnvelope::parse($token, $keys, $now, self::ISS, 'other-aud');
                return;
            case 'nbf_skew':
                TokenEnvelope::parse($token, $keys, new \DateTimeImmutable('2026-09-20T09:00:00+00:00'), self::ISS, self::AUD);
                return;
            case 'exp_skew':
                TokenEnvelope::parse($token, $keys, new \DateTimeImmutable('2026-09-20T13:00:00+00:00'), self::ISS, self::AUD);
                return;
            case 'verify_only_cannot_sign':
                new SigningKey(new KeyId('k1'), 'HS256', str_repeat('k', 32), KeyState::VerifyOnly);
                return;
            default:
                self::fail('unhandled scenario ' . $scenario);
        }
    }

    /** @param array<string, mixed> $overrides */
    private function issueWith(array $overrides): string
    {
        $claims = array_merge($this->claims(), $overrides);
        if (isset($overrides['nested'])) {
            $json = TokenEnvelope::canonicalJson($this->claims());
            $json = substr($json, 0, -1) . ',"nested":{"a":1}}';
            return $this->signRaw($json);
        }
        if (isset($overrides['extra'])) {
            $json = TokenEnvelope::canonicalJson($this->claims());
            $json = substr($json, 0, -1) . ',"extra":"x"}';
            return $this->signRaw($json);
        }
        return $this->signRaw(TokenEnvelope::canonicalJson($claims));
    }

    private function signRaw(string $signingInput): string
    {
        $mac = hash_hmac('sha256', $signingInput, str_repeat('k', 32), true);
        return TokenEnvelope::PREFIX . rtrim(strtr(base64_encode($signingInput), '+/', '-_'), '=') . '.' . rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }

    private function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** @return array<string, mixed> */
    private function claims(): array
    {
        return [
            'v' => 1,
            'alg' => 'HS256',
            'kid' => 'k1',
            'iss' => self::ISS,
            'aud' => self::AUD,
            'jti' => '00000000000000000000000000000001',
            'tenant_id' => 'tenant-a',
            'purpose' => 'confirmation',
            'action_id' => 'act-1',
            'args_fingerprint' => 'fp',
            'descriptor_digest' => 'dd',
            'expires_at' => '2026-09-20T12:00:00+00:00',
            'nbf' => '2026-09-20T10:00:00+00:00',
            'iat' => '2026-09-20T10:00:00+00:00',
            'principal' => 'user-a',
            'proposal_id' => 'p-1',
            'proposal_version' => 1,
            'tool_name' => 'tool',
        ];
    }

    private function signing(): SigningKey
    {
        return new SigningKey(new KeyId('k1'), 'HS256', str_repeat('k', 32));
    }

    private function keys(KeyState $state = KeyState::Active): TokenKeyProvider
    {
        $secret = str_repeat('k', 32);
        $id = new KeyId('k1');
        return new class ($this->signing(), new VerificationKey($id, 'HS256', $secret, $state)) implements TokenKeyProvider {
            public function __construct(private SigningKey $signing, private VerificationKey $verification)
            {
            }

            public function signingKey(string $tenantId, TokenPurpose $purpose): SigningKey
            {
                if ($tenantId !== 'tenant-a') {
                    throw ConfirmationFailure::keyDenied();
                }
                return $this->signing;
            }

            public function verificationKey(string $tenantId, TokenPurpose $purpose, KeyId $kid): ?VerificationKey
            {
                if ($tenantId !== 'tenant-a' || $kid->value !== 'k1') {
                    return null;
                }
                return $this->verification;
            }
        };
    }
}
