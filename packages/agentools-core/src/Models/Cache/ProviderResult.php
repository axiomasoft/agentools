<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Core\Models\Cache;
use AgenToolsPlatform\Core\Common\{Ref,UtcInstant};
use AgenToolsPlatform\Core\Models\ModelUsage;

/** Adapter-normalized resource operation result, not a promised provider wire API. */
final readonly class ProviderResult
{
    public function __construct(public string $status,public ?Ref $resource=null,public ?UtcInstant $expiresAt=null,
        public ?Ref $receipt=null,public ModelUsage $usage=new \AgenToolsPlatform\Core\Models\ModelUsage(),public ?string $amount=null,public ?string $currency=null)
    {
        if (!in_array($status,['created','hit','miss','deleted','unsupported','unknown'],true)
            || ($status==='created' && ($resource===null || $expiresAt===null)) || ($status==='deleted' && $receipt===null)
            || (($amount===null)!==($currency===null))
            || ($amount!==null && (preg_match('/^(0|[1-9][0-9]*)(\.[0-9]+)?$/D',$amount)!==1 || strlen($amount)>128))
            || ($currency!==null && preg_match('/^[A-Z]{3}$/D',$currency)!==1)) { throw new \InvalidArgumentException('Explicit cache outcome required'); }
    }
}
