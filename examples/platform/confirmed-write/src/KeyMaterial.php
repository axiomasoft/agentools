<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\ConfirmedWrite;

use AgenTools\Confirmation\{KeyId, KeyState, SigningKey, TokenKeyProvider, TokenPurpose, VerificationKey};
use AgenToolsPlatform\Postgres\Confirmation\Sealer;

/** Example host key resolver. Embedders resolve separate keys from their secret owner. */
final readonly class KeyMaterial implements TokenKeyProvider,Sealer
{
    public function __construct(#[\SensitiveParameter] private string $signing,#[\SensitiveParameter] private string $encryption)
    {
        if (strlen($signing)<32 || strlen($encryption)!==32) { throw new \InvalidArgumentException('Separate host signing/encryption keys required'); }
    }
    public function signingKey(string $tenantId,TokenPurpose $purpose): SigningKey
    {
        return new SigningKey(new KeyId('host-v1'),'HS256',hash_hmac('sha256',$tenantId.':'.$purpose->value,$this->signing,true));
    }
    public function verificationKey(string $tenantId,TokenPurpose $purpose,KeyId $kid): ?VerificationKey
    {
        $key=$this->signingKey($tenantId,$purpose);
        return $kid->value===$key->id->value ? new VerificationKey($key->id,$key->algorithm,$key->secret,KeyState::Active) : null;
    }
    public function seal(#[\SensitiveParameter] string $plaintext): string
    {
        $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce.sodium_crypto_secretbox($plaintext,$nonce,$this->encryption));
    }
    public function open(#[\SensitiveParameter] string $ciphertext): string
    {
        $raw=base64_decode($ciphertext,true);
        if (!is_string($raw) || strlen($raw)<SODIUM_CRYPTO_SECRETBOX_NONCEBYTES+SODIUM_CRYPTO_SECRETBOX_MACBYTES) { throw new \RuntimeException('Invalid sealed confirmation'); }
        $plain=sodium_crypto_secretbox_open(substr($raw,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),substr($raw,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$this->encryption);
        if ($plain===false) { throw new \RuntimeException('Confirmation encryption integrity conflict'); }
        return $plain;
    }
    /** @return array<string,string> */
    public function __debugInfo(): array { return ['keys'=>'[redacted]']; }
}
