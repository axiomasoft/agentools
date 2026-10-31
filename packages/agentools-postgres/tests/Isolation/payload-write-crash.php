<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Payloads;

// Process fault after the actual backend flush, before atomic rename. This
// function exists only in this child; the parent uses the native fsync.
function fsync(mixed $stream): bool
{
    if (!\fsync($stream)) {
        return false;
    }
    exit(73);
}

require dirname(__DIR__, 2).'/vendor/autoload.php';

[$directory, $context, $handle, $operation] = unserialize(stream_get_contents(STDIN));
$store = new FileEncryptedPayloadStore($directory, static fn (): string => str_repeat('k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
if ($operation === 'put') {
    $store->put($context, $handle, 'CRASH-LEFT-CIPHERTEXT');
} else {
    $store->purge($context, $handle, 'crashed-purge');
}
exit(74);
