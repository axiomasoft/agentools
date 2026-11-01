<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Skills;

use AgenToolsPlatform\Core\Common\{CanonicalJson, PayloadHash, RunId, SessionId};
use AgenToolsPlatform\Core\Profiles\{DefinitionRef, ResourceKind, ResourceRef};

final readonly class SelectionRequest
{
    /**
     * @var list<ResourceRef>
     */ public array $pins;
    /**
     * @var list<ResourceRef>
     */ public array $routes;
    /**
     * @var list<string>
     */ public array $contextKinds;

    /**
     * @param list<ResourceRef> $pins
     * @param list<ResourceRef> $routes
     * @param array<array-key, mixed> $contextKinds
     */
    public function __construct(public RunId $run, public SessionId $session, public RuntimeScope $scope,
        public string $purpose, public string $task, array $contextKinds, public PayloadHash $contextHash,
        array $pins, array $routes = [], public int $shortlistLimit = 8, public int $tokenCap = 4096)
    {
        $run->tenant->assertSame($session->tenant); $run->tenant->assertSame($scope->bundle->bundle->ref->tenant);
        Bounds::names([$purpose,$task]); $this->contextKinds=Bounds::names($contextKinds);
        if (count($pins) > 256 || count($routes) > 1 || $shortlistLimit < 1 || $shortlistLimit > 16
            || $tokenCap < 0 || $tokenCap > 65536) { throw new \InvalidArgumentException('Bounded pinned routing inputs required'); }
        $seen = [];
        foreach ($pins as $pin) {
            $pin->owner->assertVisible($run->tenant);
            if ($pin->kind !== ResourceKind::Skill || isset($seen[$pin->key()])) { throw new \InvalidArgumentException('Distinct explicit skill version pins required'); }
            $seen[$pin->key()]=$pin->version->hash->value;
        }
        $routed = [];
        foreach ($routes as $route) {
            if (($seen[$route->key()] ?? null) !== $route->version->hash->value || isset($routed[$route->key()])) {
                throw new \AgenToolsPlatform\Core\Profiles\Skills\SelectionFailure('ROUTE_NOT_PINNED');
            }
            $routed[$route->key()]=true;
        }
        $sort=static fn (ResourceRef $a, ResourceRef $b): int => strcmp($a->key(),$b->key());
        usort($pins,$sort); usort($routes,$sort); $this->pins=$pins; $this->routes=$routes;
    }
    /** Semantic routing digest excludes Run identity; replay binding is stored separately. */
    public function hash(CanonicalJson $json, DefinitionRef $counter): PayloadHash
    {
        return new \AgenToolsPlatform\Core\Common\PayloadHash('sha-256:'.hash('sha256',$json->canonicalize([
            'format'=>'skill-routing/v1','tenant'=>$this->run->tenant->value,'session'=>$this->session->toArray(),'scope'=>$this->scope->identity(),
            'purpose'=>$this->purpose,'task'=>$this->task,'context_kinds'=>$this->contextKinds,'context_hash'=>$this->contextHash->value,
            'pins'=>array_map(static fn (ResourceRef $r): array => $r->toArray(),$this->pins),
            'routes'=>array_map(static fn (ResourceRef $r): array => $r->toArray(),$this->routes),
            'shortlist_limit'=>$this->shortlistLimit,'token_cap'=>$this->tokenCap,'counter'=>$counter->toArray()])));
    }
}
