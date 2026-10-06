<?php

declare(strict_types=1);

namespace AgenTools\Tests\Confirmation;

use AgenTools\Confirmation\HmacTokenSigner;
use AgenTools\Confirmation\KeyId;
use AgenTools\Confirmation\SigningKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Signing secrets never surface in stack traces or debug dumps. */
final class SigningSecretHygieneTest extends TestCase
{
    private const SECRET = 'do-not-leak-this-signing-secret-0123456789';

    /** @return iterable<string, array{class-string}> */
    public static function secretHolders(): iterable
    {
        yield 'SigningKey' => [SigningKey::class];
        yield 'HmacTokenSigner' => [HmacTokenSigner::class];
    }

    /** @param class-string $class */
    #[DataProvider('secretHolders')]
    public function testSecretParameterIsSensitive(string $class): void
    {
        $constructor = (new \ReflectionClass($class))->getConstructor();
        self::assertNotNull($constructor);
        $secret = array_values(array_filter(
            $constructor->getParameters(),
            static fn (\ReflectionParameter $parameter): bool => $parameter->getName() === 'secret',
        ));

        self::assertCount(1, $secret);
        self::assertCount(1, $secret[0]->getAttributes(\SensitiveParameter::class));
    }

    public function testSigningKeyDumpRedactsTheSecret(): void
    {
        $key = new SigningKey(new KeyId('k1'), 'HS256', self::SECRET);

        self::assertStringNotContainsString(self::SECRET, print_r($key, true));
        self::assertStringNotContainsString(self::SECRET, $this->varDump($key));
        self::assertSame('[redacted]', $key->__debugInfo()['secret']);
        self::assertSame('k1', $key->__debugInfo()['id']->value);
    }

    public function testHmacSignerDumpRedactsTheSecret(): void
    {
        $signer = new HmacTokenSigner(self::SECRET);

        self::assertStringNotContainsString(self::SECRET, print_r($signer, true));
        self::assertStringNotContainsString(self::SECRET, $this->varDump($signer));
    }

    public function testHmacSignerRejectsShortSecrets(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 32 bytes');

        new HmacTokenSigner(str_repeat('s', 31));
    }

    public function testHmacSignerSignsAndVerifiesWithAThirtyTwoByteSecret(): void
    {
        $signer = new HmacTokenSigner(str_repeat('s', 32));
        $signature = $signer->sign('payload');

        self::assertSame(hash_hmac('sha256', 'payload', str_repeat('s', 32), true), $signature);
        self::assertTrue($signer->verify('payload', $signature));
        self::assertFalse($signer->verify('payload-2', $signature));
    }

    private function varDump(object $value): string
    {
        ob_start();
        var_dump($value);

        return (string) ob_get_clean();
    }
}
