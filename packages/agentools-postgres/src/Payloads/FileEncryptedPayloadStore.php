<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Payloads;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, IntegrityConflict, Ref};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Runtime\StorageFailure;

/** Explicit private disk backend. Keys are resolved per operation; policy remains in PayloadRepository. */
final readonly class FileEncryptedPayloadStore implements EncryptedPayloadStore
{
    private string $root;

    /** @param \Closure(Ref):string $keyProvider Returns an exact sodium key; never a model-supplied key. */
    public function __construct(string $root, private \Closure $keyProvider)
    {
        $resolved=realpath($root);
        $permissions=$resolved===false?false:fileperms($resolved);
        if ($resolved===false || !is_dir($resolved) || is_link($root) || $resolved===DIRECTORY_SEPARATOR
            || $permissions===false || ($permissions & 0077)!==0
            || !function_exists('sodium_crypto_secretbox')) {
            throw new \InvalidArgumentException('Existing private payload directory and sodium required');
        }
        $this->root=$resolved;
    }

    public function put(ExecutionContext $context, PayloadHandle $handle, string $bytes): void
    {
        $this->locked($context,$handle,function (string $path) use ($handle,$bytes): void {
            if (is_file($path.'.purged')) {
                throw new IntegrityConflict('Payload tombstone cannot be reopened');
            }
            if (is_file($path)) {
                if (!hash_equals(hash('sha256',$this->decode($handle,$path)),hash('sha256',$bytes))) {
                    throw new IntegrityConflict('Encrypted payload identity conflict');
                }
                return;
            }
            $key=$this->key($handle);
            try {
                $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
                $this->write($path,'ATPS1'.$nonce.sodium_crypto_secretbox($bytes,$nonce,$key));
            } finally {
                sodium_memzero($key);
            }
        });
    }

    public function read(ExecutionContext $context, PayloadHandle $handle): string
    {
        return $this->locked($context,$handle,function (string $path) use ($handle): string {
            if (is_file($path.'.purged')) {
                throw new ContractViolation(ErrorCode::AccessDenied);
            }
            return $this->decode($handle,$path);
        });
    }

    public function supportsIdempotentPurge(): bool
    {
        return true;
    }

    public function purge(ExecutionContext $context, PayloadHandle $handle, string $operationKey): void
    {
        if ($operationKey==='') {
            throw new ContractViolation(ErrorCode::InvalidInput);
        }
        $this->locked($context,$handle,function (string $path) use ($operationKey): void {
            // Tombstone first: crash/restart must never permit a put to recreate erased work.
            if (!is_file($path.'.purged')) {
                $this->write($path.'.purged',hash('sha256',$operationKey));
            }
            if (is_file($path) && !unlink($path)) {
                throw new StorageFailure();
            }
        });
    }

    public function reset(): void
    {
        // No decrypted payload, resolved key, handle or credentials are cached by this backend.
    }

    private function key(PayloadHandle $handle): string
    {
        try {
            $key=($this->keyProvider)($handle->encryptionKeyRef);
        } catch (\Throwable) {
            throw new StorageFailure();
        }
        if (strlen($key)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new StorageFailure();
        }
        return $key;
    }

    private function decode(PayloadHandle $handle, string $path): string
    {
        $blob=is_file($path)?file_get_contents($path):false;
        if (!is_string($blob) || !str_starts_with($blob,'ATPS1') || strlen($blob)<5+SODIUM_CRYPTO_SECRETBOX_NONCEBYTES+SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new StorageFailure();
        }
        $key=$this->key($handle);
        try {
            $bytes=sodium_crypto_secretbox_open(substr($blob,5+SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                substr($blob,5,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$key);
            if ($bytes===false) {
                throw new IntegrityConflict('Encrypted payload authentication failed');
            }
            return $bytes;
        } finally {
            sodium_memzero($key);
        }
    }

    /**
     * @template T
     * @param \Closure(string):T $operation
     * @return T
     */
    private function locked(ExecutionContext $context, PayloadHandle $handle, \Closure $operation): mixed
    {
        $context->identity->tenant->assertSame($handle->slot->tenant);
        $id=hash('sha256',json_encode([$handle->slot->toArray(),$handle->encryptedRef->toArray(),$handle->encryptionKeyRef->toArray()],JSON_THROW_ON_ERROR));
        $path=$this->root.DIRECTORY_SEPARATOR.$id;
        if (is_link($path) || is_link($path.'.lock') || is_link($path.'.purged')) {
            throw new StorageFailure();
        }
        $lock=fopen($path.'.lock','c+b');
        if ($lock===false) {
            throw new StorageFailure();
        }
        try {
            if (!flock($lock,LOCK_EX)) {
                throw new StorageFailure();
            }
            return $operation($path);
        } finally {
            flock($lock,LOCK_UN);
            fclose($lock);
        }
    }

    private function write(string $path, string $bytes): void
    {
        $temporary=tempnam($this->root,'write-');
        if ($temporary===false) {
            throw new StorageFailure();
        }
        $file=null;
        try {
            if (!chmod($temporary,0600)) {
                throw new StorageFailure();
            }
            $file=fopen($temporary,'wb');
            if ($file===false || fwrite($file,$bytes)!==strlen($bytes) || !fflush($file) || !fsync($file)) {
                throw new StorageFailure();
            }
            fclose($file);
            $file=null;
            if (!rename($temporary,$path)) {
                throw new StorageFailure();
            }
        } finally {
            if (is_resource($file)) {
                fclose($file);
            }
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
