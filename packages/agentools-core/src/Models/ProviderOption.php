<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models;

/** Adapter must recognize the exact provider/name and validate the JSON value before IO. */
final readonly class ProviderOption implements \AgenToolsPlatform\Core\Models\ModelOption
{
    public function __construct(public string $provider, public string $name, public JsonObject $value)
    {
        if ($provider === '' || $name === '') { throw new \InvalidArgumentException('Provider option needs an owner and name'); }
    }
    public function capability(): Capability { return Capability::ProviderOptions; }
}
