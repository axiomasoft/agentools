<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Tests\Definitions;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, IntegrityConflict, PayloadHash, TenantId};
use AgenToolsPlatform\Core\Profiles\DefinitionRef;
use AgenToolsPlatform\Laravel\Definitions\{DraftSettings, OwnerScope, Reference, Registry, Tone};
use PHPUnit\Framework\TestCase;

final class RegistryTest extends TestCase
{
    public function testImmutableRegistryAndExplicitOwnership(): void
    {
        $a=Fixture::definition();$b=Fixture::definition();self::assertSame($a->ref->toArray(),$b->ref->toArray());
        $tenant=Fixture::definition(OwnerScope::tenant(new TenantId('alpha')));
        self::assertNotSame($a->ref->version->hash->value,$tenant->ref->version->hash->value);
        $registry=new Registry([$a,$tenant]);self::assertSame($a,$registry->resolve($a->ref,new TenantId('beta')));
        self::assertSame($tenant,$registry->resolve($tenant->ref,new TenantId('alpha')));
        $untyped=$a->ref->toArray();$untyped['owner']=['tenant_id'=>null];
        try{Reference::fromJson(json_encode($untyped,JSON_THROW_ON_ERROR));self::fail('Implicit platform scope accepted');}catch(\InvalidArgumentException){}
        $this->expectException(ContractViolation::class);$registry->resolve($tenant->ref,new TenantId('beta'));
    }
    public function testDuplicatesAndDifferingHashesFail(): void
    {
        $a=Fixture::definition();
        try { new Registry([$a,$a]);self::fail('Duplicate accepted'); } catch (ContractViolation) {}
        $bad=new Reference($a->ref->owner,new DefinitionRef($a->ref->version->id,'1',new PayloadHash('sha-256:'.str_repeat('0',64))));
        $this->expectException(IntegrityConflict::class);(new Registry([$a]))->resolve($bad,new TenantId('alpha'));
    }
    public function testDraftsCannotEditPublishedBehaviorOrGuards(): void
    {
        $definition=Fixture::definition();$hash=$definition->ref->version->hash->value;
        self::assertSame(Tone::Concise,$definition->preview(DraftSettings::fromJson('{"tone":"concise"}'))->tone);
        self::assertSame(Tone::Neutral,$definition->defaults->tone);self::assertSame($hash,$definition->ref->version->hash->value);
        foreach (['handler','sql','tools','budget','skip_guards','confirmation','class','profile'] as $field) {
            try { DraftSettings::fromJson(json_encode([$field=>'arbitrary'],JSON_THROW_ON_ERROR));self::fail('Executable/authority field accepted'); }
            catch (\InvalidArgumentException) {}
        }
        $this->expectException(ContractViolation::class);$definition->preview(new DraftSettings(displayName:'Locked'));
    }
    public function testRevocationOverPinnedDefinitionIsCurrent(): void
    {
        $d=Fixture::definition();$r=new Registry([$d]);$current=new Current();$context=Fixture::context();
        self::assertSame($d,$r->current($d->ref,$context,$current));$current->allowed=false;
        try {$r->current($d->ref,$context,$current);self::fail('Pin granted authority');}
        catch(ContractViolation $error){self::assertSame(ErrorCode::AccessDenied,$error->errorCode);}
    }
}
