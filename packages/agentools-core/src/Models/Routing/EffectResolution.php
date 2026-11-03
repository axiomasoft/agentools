<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Core\Models\Routing;
use AgenToolsPlatform\Core\Common\Ref;

/** Opaque authoritative lookup evidence, not an Action wire-status extension or permission to replay. */
final readonly class EffectResolution
{
    public function __construct(public Ref $operation,public Ref $action,public string $outcome,public ?Ref $evidence=null)
    {
        $operation->tenant->assertSame($action->tenant); if ($evidence!==null) { $operation->tenant->assertSame($evidence->tenant); }
        if (!in_array($outcome,['unknown','success','failed','not_applied'],true) || ($outcome!=='unknown' && $evidence===null)) {
            throw new \InvalidArgumentException('Known owner effect resolution requires evidence');
        }
    }
}
