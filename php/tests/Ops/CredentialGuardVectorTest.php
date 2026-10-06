<?php

declare(strict_types=1);

namespace AgenTools\Tests\Ops;

use AgenTools\Ops\CredentialGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Credential vectors from fixtures/<rev>/vectors/l1/security-*.json — unit replay of the guard
 * verdict against the normative expectation, independent of the conformance answer catalog.
 */
final class CredentialGuardVectorTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function credentialVectors(): iterable
    {
        foreach (['2026-08', '2026-09'] as $revision) {
            foreach (['001', '002', '003', '005', '006', '101', '102', '103', '105', '106'] as $number) {
                yield $revision . '/security-' . $number => [$revision, 'security-' . $number];
            }
        }
    }

    /** SEC-1, SEC-2, SEC-3, SEC-5, SEC-6; the guard reproduces reason and stimulus of each vector. */
    #[DataProvider('credentialVectors')]
    public function testGuardVerdictMatchesVector(string $revision, string $vectorId): void
    {
        $vector = $this->vector($revision, $vectorId);
        $credential = $this->path($vector, ['given', 'driver_state', 'credentials', 0]);
        self::assertIsArray($credential);
        /** @var array<string, mixed> $credential */
        $result = (new CredentialGuard('agentools-runtime', ['issuer-trusted']))->validateAccessToken($credential);

        if ($this->path($vector, ['expect', 'final_state']) !== 'failed') {
            self::assertNull($result, $vectorId . ' must pass the guard');

            return;
        }

        $expected = $this->path($vector, ['expect', 'events', 0, 'payload']);
        self::assertIsArray($expected);
        $context = $result?->error?->context;
        self::assertIsArray($context, $vectorId . ' must be rejected');
        $field = $context['credential_field'] ?? null;
        self::assertIsString($field);
        self::assertSame($expected['reason'] ?? null, $context['reason'] ?? null);
        self::assertSame($expected['stimulus_pointer'] ?? null, '/given/driver_state/credentials/0/' . $field);
        if (($expected['security_rule'] ?? null) !== 'SEC-3') {
            // SEC-3 vectors annotate the error-shape rule under test, not the violated rule.
            self::assertSame($expected['security_rule'] ?? null, $context['security_rule'] ?? null);
        }
    }

    /** @return array<string, mixed> */
    private function vector(string $revision, string $vectorId): array
    {
        $path = dirname(__DIR__, 3) . '/fixtures/' . $revision . '/vectors/l1/' . $vectorId . '.json';
        $vector = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($vector)) {
            throw new \RuntimeException('Vector fixture must decode to an object.');
        }

        /** @var array<string, mixed> $vector */
        return $vector;
    }

    /**
     * @param array<string, mixed> $document
     * @param list<string|int> $path
     */
    private function path(array $document, array $path): mixed
    {
        $value = $document;
        foreach ($path as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
