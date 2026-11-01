<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Postgres\Confirmation;

/** Authenticated encryption supplied by the host; keys never enter platform records. */
interface Sealer
{
    public function seal(#[\SensitiveParameter] string $plaintext): string;
    public function open(#[\SensitiveParameter] string $ciphertext): string;
}
