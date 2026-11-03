<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Laravel\Observability;

/** Separate explicit disclosure result. Debug/JSON/serialization never expose its bytes. */
final class SensitivePayload implements \JsonSerializable
{
    public function __construct(#[\SensitiveParameter] private string $bytes) {}
    public function reveal(): string { return $this->bytes; }
    /** @return array{redacted:bool} */
    public function jsonSerialize(): array { return ['redacted'=>true]; }
    /** @return array{redacted:bool} */
    public function __debugInfo(): array { return ['redacted'=>true]; }
    /** @return never */
    public function __serialize(): array { throw new \LogicException('Diagnostic plaintext cannot be serialized'); }
    public function __destruct() { $bytes=$this->bytes;$this->bytes='';sodium_memzero($bytes); }
}
