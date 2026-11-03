<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Conformance\Providers;
use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Models\Routing\{EffectLookup,EffectResolution};
use AgenToolsPlatform\Tests\Routing\Fixture as F;

/** Test-only stand-in for an already accepted owner operation, never an executor callback. */
final class Owner implements EffectLookup
{
    public string $outcome='unknown'; public int $effectCount=1;
    /** @var list<string> */ public array $lookups=[];
    public bool $wrongOperation=false;
    public function status(Ref $operation): EffectResolution
    {
        $this->lookups[]=$operation->value;
        return new EffectResolution($this->wrongOperation?F::ref('wrong-owner-key'):$operation,F::ref('action-1'),$this->outcome,
            $this->outcome==='unknown'?null:F::ref('authoritative-receipt'));
    }
}
