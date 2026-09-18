<?php

declare(strict_types=1);

namespace AgenTools\Confirmation;

use AgenTools\Contracts\TokenSigner;
use AgenTools\Idempotency\ArgumentsFingerprint;
use AgenTools\Tool\DescriptorDigest;

/** Opaque signed handle for the ordinary confirmation_token tool argument. */
final readonly class ConfirmationToken
{
    private function __construct(
        public string $value,
        public string $proposalId,
        public int $proposalVersion,
        public string $actionId,
        public string $principal,
        public string $toolName,
        public ArgumentsFingerprint $argsFingerprint,
        public DescriptorDigest $descriptorDigest,
        public \DateTimeImmutable $expiresAt,
    ) {
    }

    public static function issue(Proposal $proposal, TokenSigner $signer): self
    {
        $payload = json_encode([
            'action_id' => $proposal->actionId,
            'args_fingerprint' => $proposal->argsFingerprint->value,
            'descriptor_digest' => $proposal->descriptorDigest->value,
            'expires_at' => $proposal->expiresAt->format(DATE_ATOM),
            'principal' => $proposal->principal,
            'proposal_id' => $proposal->proposalId,
            'proposal_version' => $proposal->proposalVersion,
            'tool_name' => $proposal->toolName,
        ], JSON_THROW_ON_ERROR);
        $encoded = self::encode($payload);
        return new self($encoded . '.' . self::encode($signer->sign($encoded)), $proposal->proposalId, $proposal->proposalVersion, $proposal->actionId, $proposal->principal, $proposal->toolName, $proposal->argsFingerprint, $proposal->descriptorDigest, $proposal->expiresAt);
    }

    public static function parse(string $token, TokenSigner $signer): self
    {
        [$encoded, $signature] = explode('.', $token, 2) + [null, null];
        if (!is_string($encoded) || !is_string($signature) || !$signer->verify($encoded, self::decode($signature))) {
            throw new \InvalidArgumentException('The confirmation token signature is invalid.');
        }
        try {
            $payload = json_decode(self::decode($encoded), true, 512, JSON_THROW_ON_ERROR);
            $expiresAt = new \DateTimeImmutable(self::string($payload, 'expires_at'));
            return new self($token, self::string($payload, 'proposal_id'), self::integer($payload, 'proposal_version'), self::string($payload, 'action_id'), self::string($payload, 'principal'), self::string($payload, 'tool_name'), new ArgumentsFingerprint(self::string($payload, 'args_fingerprint')), self::digest(self::string($payload, 'descriptor_digest')), $expiresAt);
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException('The confirmation token payload is invalid.', previous: $exception);
        }
    }

    private static function digest(string $value): DescriptorDigest
    {
        return DescriptorDigest::fromValue($value);
    }

    private static function string(mixed $payload, string $key): string
    {
        if (!is_array($payload) || !isset($payload[$key]) || !is_string($payload[$key])) {
            throw new \InvalidArgumentException('Missing token field ' . $key . '.');
        }
        return $payload[$key];
    }

    private static function integer(mixed $payload, string $key): int
    {
        if (!is_array($payload) || !isset($payload[$key]) || !is_int($payload[$key])) {
            throw new \InvalidArgumentException('Missing token field ' . $key . '.');
        }
        return $payload[$key];
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function decode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        if (!is_string($decoded)) {
            throw new \InvalidArgumentException('Invalid base64url token segment.');
        }
        return $decoded;
    }
}
