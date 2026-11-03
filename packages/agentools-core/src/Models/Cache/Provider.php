<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Core\Models\Cache;
use AgenToolsPlatform\Core\Common\{Ref,UtcInstant};

/** Required host adapter; existing transports do not acquire cache support by implementing this port. */
interface Provider
{
    public function capabilities(Key $key): Capabilities;
    public function create(Key $key,Ref $operation,UtcInstant $expiry): ProviderResult;
    public function use(Descriptor $descriptor): ProviderResult;
    public function refresh(Descriptor $descriptor,Ref $operation,UtcInstant $expiry): ProviderResult;
    public function delete(Descriptor $descriptor,Ref $operation): ProviderResult;
    public function status(Key $key,Ref $operation): ProviderResult;
}
