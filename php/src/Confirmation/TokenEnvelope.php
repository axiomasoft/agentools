<?php

declare(strict_types=1);

namespace AgenTools\Confirmation;

/** Bounded at1 confirmation-token codec. Dual-read of legacy two-segment tokens is forbidden. */
final readonly class TokenEnvelope
{
    public const PREFIX = 'at1.';
    public const MAX_RAW_BYTES = 8192;
    public const ALGORITHM = 'HS256';
    public const VERSION = 1;
    public const DEFAULT_SKEW_SECONDS = 60;
    public const MAX_SKEW_SECONDS = 300;
    public const DEFAULT_MAX_LIFETIME_SECONDS = 259200;
    public const HARD_MAX_LIFETIME_SECONDS = 604800;
    public const MIN_RETENTION_SECONDS = self::MAX_SKEW_SECONDS;
    public const REQUIRED_CLAIMS = [
        'v', 'alg', 'kid', 'iss', 'aud', 'jti', 'tenant_id', 'purpose',
        'action_id', 'args_fingerprint', 'descriptor_digest', 'expires_at',
        'nbf', 'iat', 'principal', 'proposal_id', 'proposal_version', 'tool_name',
    ];

    /** @param array<string, mixed> $claims */
    public function __construct(
        public string $value,
        public array $claims,
        public string $jti,
        public string $tenantId,
        public string $kid,
    ) {
    }

    /** @param array<string, mixed> $claims */
    public static function issue(array $claims, SigningKey $key): self
    {
        self::assertClosedClaims($claims);
        self::assertTimeClaims($claims);
        if (($claims['alg'] ?? null) !== $key->algorithm || ($claims['kid'] ?? null) !== $key->id->value) {
            throw ConfirmationFailure::keyDenied();
        }
        $signingInput = self::canonicalJson($claims);
        $mac = hash_hmac('sha256', $signingInput, $key->secret, true);
        $value = self::PREFIX . self::b64($signingInput) . '.' . self::b64($mac);
        if (strlen($value) > self::MAX_RAW_BYTES) {
            throw ConfirmationFailure::tokenInvalid();
        }
        return new self($value, $claims, self::stringClaim($claims, 'jti'), self::stringClaim($claims, 'tenant_id'), $key->id->value);
    }

    public static function parse(
        string $token,
        TokenKeyProvider $keys,
        \DateTimeImmutable $now,
        string $expectedIss,
        string $expectedAud,
        int $skewSeconds = self::DEFAULT_SKEW_SECONDS,
    ): self {
        if (strlen($token) > self::MAX_RAW_BYTES || !str_starts_with($token, self::PREFIX)) {
            throw ConfirmationFailure::tokenInvalid();
        }
        $rest = substr($token, strlen(self::PREFIX));
        $parts = explode('.', $rest);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw ConfirmationFailure::tokenInvalid();
        }
        $signingInput = self::unb64($parts[0]);
        $mac = self::unb64($parts[1]);
        self::rejectDuplicateNames($signingInput);
        try {
            $decoded = json_decode($signingInput, true, 2, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw ConfirmationFailure::tokenInvalid();
        }
        $claims = self::stringKeyed($decoded);
        if (self::canonicalJson($claims) !== $signingInput) {
            throw ConfirmationFailure::tokenInvalid();
        }
        self::assertClosedClaims($claims);
        self::assertTimeClaims($claims);
        $purpose = TokenPurpose::tryFrom(self::stringClaim($claims, 'purpose'));
        if ($purpose === null) {
            throw ConfirmationFailure::tokenBindingMismatch();
        }
        $kid = new KeyId(self::stringClaim($claims, 'kid'));
        $tenantId = self::stringClaim($claims, 'tenant_id');
        $key = $keys->verificationKey($tenantId, $purpose, $kid);
        if ($key === null || !$key->canVerify() || $key->algorithm !== self::stringClaim($claims, 'alg')) {
            throw ConfirmationFailure::keyDenied();
        }
        if (!hash_equals(hash_hmac('sha256', $signingInput, $key->secret, true), $mac)) {
            throw ConfirmationFailure::tokenInvalid();
        }
        if (self::stringClaim($claims, 'iss') !== $expectedIss || self::stringClaim($claims, 'aud') !== $expectedAud) {
            throw ConfirmationFailure::tokenBindingMismatch();
        }
        $skew = min(max($skewSeconds, 0), self::MAX_SKEW_SECONDS);
        $nbf = self::timeClaim($claims, 'nbf');
        $exp = self::timeClaim($claims, 'expires_at');
        if ($now->getTimestamp() + $skew < $nbf->getTimestamp()) {
            throw ConfirmationFailure::notYetValid();
        }
        if ($now->getTimestamp() - $skew >= $exp->getTimestamp()) {
            throw ConfirmationFailure::expired();
        }
        return new self($token, $claims, self::stringClaim($claims, 'jti'), $tenantId, $kid->value);
    }

    public static function newJti(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** @return array<string, mixed> */
    private static function stringKeyed(mixed $decoded): array
    {
        if (!is_array($decoded) || $decoded === [] || array_is_list($decoded)) {
            throw ConfirmationFailure::tokenInvalid();
        }
        $claims = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                throw ConfirmationFailure::tokenInvalid();
            }
            $claims[$key] = $value;
        }
        return $claims;
    }

    /** @param array<string, mixed> $claims */
    public static function canonicalJson(array $claims): string
    {
        ksort($claims);
        $members = [];
        foreach ($claims as $key => $value) {
            $members[] = self::encodeString((string) $key) . ':' . self::encodeValue($value);
        }
        return '{' . implode(',', $members) . '}';
    }

    /** @param array<string, mixed> $claims */
    private static function assertClosedClaims(array $claims): void
    {
        if (count($claims) !== count(self::REQUIRED_CLAIMS)) {
            throw ConfirmationFailure::tokenInvalid();
        }
        foreach (self::REQUIRED_CLAIMS as $name) {
            if (!array_key_exists($name, $claims)) {
                throw ConfirmationFailure::tokenInvalid();
            }
        }
        if ($claims['v'] !== self::VERSION) {
            throw ConfirmationFailure::tokenInvalid();
        }
        if (!is_string($claims['alg']) || $claims['alg'] === 'none' || $claims['alg'] !== self::ALGORITHM) {
            throw ConfirmationFailure::keyDenied();
        }
        foreach (['kid', 'iss', 'aud', 'jti', 'tenant_id', 'purpose', 'action_id', 'args_fingerprint', 'descriptor_digest', 'expires_at', 'nbf', 'iat', 'principal', 'proposal_id', 'tool_name'] as $name) {
            if (!is_string($claims[$name]) || $claims[$name] === '') {
                throw ConfirmationFailure::tokenInvalid();
            }
        }
        if (!is_int($claims['proposal_version']) || $claims['proposal_version'] < 1) {
            throw ConfirmationFailure::tokenInvalid();
        }
        if (TokenPurpose::tryFrom($claims['purpose']) === null) {
            throw ConfirmationFailure::tokenBindingMismatch();
        }
        foreach ($claims as $value) {
            if (is_array($value)) {
                throw ConfirmationFailure::tokenInvalid();
            }
        }
    }

    /** @param array<string, mixed> $claims */
    private static function stringClaim(array $claims, string $name): string
    {
        $value = $claims[$name] ?? null;
        if (!is_string($value) || $value === '') {
            throw ConfirmationFailure::tokenInvalid();
        }
        return $value;
    }

    /** @param array<string, mixed> $claims */
    private static function timeClaim(array $claims, string $name): \DateTimeImmutable
    {
        $value = self::stringClaim($claims, $name);
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/D', $value) !== 1) {
            throw ConfirmationFailure::tokenInvalid();
        }
        try {
            $time = new \DateTimeImmutable($value);
            $errors = \DateTimeImmutable::getLastErrors();
            if (is_array($errors) && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0)) {
                throw ConfirmationFailure::tokenInvalid();
            }
            return $time;
        } catch (\Throwable) {
            throw ConfirmationFailure::tokenInvalid();
        }
    }

    /** @param array<string, mixed> $claims */
    private static function assertTimeClaims(array $claims): void
    {
        $iat = self::timeClaim($claims, 'iat');
        self::timeClaim($claims, 'nbf');
        $expiresAt = self::timeClaim($claims, 'expires_at');
        $lifetime = $expiresAt->getTimestamp() - $iat->getTimestamp();
        if ($lifetime < 1 || $lifetime > self::HARD_MAX_LIFETIME_SECONDS) {
            throw ConfirmationFailure::tokenInvalid();
        }
    }

    private static function encodeValue(mixed $value): string
    {
        if (is_string($value)) {
            return self::encodeString($value);
        }
        if (is_int($value)) {
            return (string) $value;
        }
        throw ConfirmationFailure::tokenInvalid();
    }

    private static function encodeString(string $value): string
    {
        $out = '"';
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $c = $value[$i];
            $ord = ord($c);
            if ($c === '"' || $c === '\\') {
                $out .= '\\' . $c;
            } elseif ($ord < 0x20) {
                $out .= sprintf('\\u%04x', $ord);
            } else {
                $out .= $c;
            }
        }
        return $out . '"';
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        if (!is_string($decoded)) {
            throw ConfirmationFailure::tokenInvalid();
        }
        return $decoded;
    }

    private static function rejectDuplicateNames(string $json): void
    {
        $i = 0;
        $n = strlen($json);
        self::skipWs($json, $i, $n);
        if ($i >= $n || $json[$i] !== '{') {
            throw ConfirmationFailure::tokenInvalid();
        }
        $i++;
        $keys = [];
        self::skipWs($json, $i, $n);
        if ($i < $n && $json[$i] === '}') {
            return;
        }
        while ($i < $n) {
            self::skipWs($json, $i, $n);
            $key = self::readJsonString($json, $i, $n);
            if (isset($keys[$key])) {
                throw ConfirmationFailure::tokenInvalid();
            }
            $keys[$key] = true;
            self::skipWs($json, $i, $n);
            if ($i >= $n || $json[$i] !== ':') {
                throw ConfirmationFailure::tokenInvalid();
            }
            $i++;
            self::skipJsonValue($json, $i, $n);
            self::skipWs($json, $i, $n);
            if ($i < $n && $json[$i] === ',') {
                $i++;
                continue;
            }
            if ($i < $n && $json[$i] === '}') {
                return;
            }
            throw ConfirmationFailure::tokenInvalid();
        }
        throw ConfirmationFailure::tokenInvalid();
    }

    private static function skipWs(string $json, int &$i, int $n): void
    {
        while ($i < $n && ctype_space($json[$i])) {
            $i++;
        }
    }

    private static function readJsonString(string $json, int &$i, int $n): string
    {
        if ($i >= $n || $json[$i] !== '"') {
            throw ConfirmationFailure::tokenInvalid();
        }
        $i++;
        $out = '';
        while ($i < $n) {
            $c = $json[$i];
            $i++;
            if ($c === '"') {
                return $out;
            }
            if ($c === '\\') {
                if ($i >= $n) {
                    throw ConfirmationFailure::tokenInvalid();
                }
                $out .= '\\' . $json[$i];
                $i++;
                continue;
            }
            $out .= $c;
        }
        throw ConfirmationFailure::tokenInvalid();
    }

    private static function skipJsonValue(string $json, int &$i, int $n): void
    {
        self::skipWs($json, $i, $n);
        if ($i >= $n) {
            throw ConfirmationFailure::tokenInvalid();
        }
        $c = $json[$i];
        if ($c === '"') {
            self::readJsonString($json, $i, $n);
            return;
        }
        if ($c === '{') {
            $depth = 0;
            do {
                if ($json[$i] === '{') {
                    $depth++;
                } elseif ($json[$i] === '}') {
                    $depth--;
                } elseif ($json[$i] === '"') {
                    self::readJsonString($json, $i, $n);
                    continue;
                }
                $i++;
            } while ($i < $n && $depth > 0);
            return;
        }
        if ($c === '[') {
            throw ConfirmationFailure::tokenInvalid();
        }
        while ($i < $n && !in_array($json[$i], [',', '}', ']'], true) && !ctype_space($json[$i])) {
            $i++;
        }
    }
}
