<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Testing\Composition;

use AgenToolsPlatform\Core\Common\{DeliveryId,Ref};

final readonly class DeliveryResult
{
    public function __construct(public DeliveryId $id,public ?Ref $receipt,public ?int $acknowledgedUnits)
    {
        if($receipt!==null){$id->tenant->assertSame($receipt->tenant);}
        if(($receipt===null)!==($acknowledgedUnits===null) || ($acknowledgedUnits!==null && $acknowledgedUnits<0)){throw new \InvalidArgumentException('Unknown acknowledgement must remain null');}
    }
}
