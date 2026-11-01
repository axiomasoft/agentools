<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/Fixtures.php';

use AgenToolsPlatform\Core\Actions\Confirmation\{Binding, ConfirmationGateway};
use AgenToolsPlatform\Core\Common\{ActionId, CanonicalHasher, PayloadHash, TenantId, UtcInstant};
use AgenToolsPlatform\Tests\{Harness as H, JcsAdapter, TestHost};
use AgenToolsPlatform\Tests\Actions\Fixtures as F;

$cases=[
    'confirmation.binding-tuple'=>static function (): void {
        $hasher=new CanonicalHasher(new JcsAdapter());
        $host=new TestHost(new TenantId('tenant-a'));
        $submission=F::submission(F::definition(),F::context($host));
        $id=new ActionId($host->tenant,'action');
        $previewHash=new PayloadHash('sha-256:'.str_repeat('a',64));
        $expiry=new UtcInstant('2099-01-01T00:00:00Z');
        $base=new Binding($id,$submission,3,0,$expiry,$previewHash,$hasher);
        foreach ([new Binding($id,$submission,4,0,$expiry,$previewHash,$hasher),new Binding($id,$submission,3,1,$expiry,$previewHash,$hasher),
            new Binding($id,$submission,3,0,new UtcInstant('2099-01-01T00:00:01Z'),$previewHash,$hasher),
            new Binding($id,$submission,3,0,$expiry,new PayloadHash('sha-256:'.str_repeat('b',64)),$hasher)] as $changed) {
            H::check($base->hash->value!==$changed->hash->value);
        }
        H::check($base->hash->value===(new Binding($id,$submission,3,0,$expiry,$previewHash,$hasher))->hash->value);
        H::check(!str_contains(json_encode($base->toArray(),JSON_THROW_ON_ERROR),'hello'));
        H::rejects(static fn()=>new Binding($id,$submission,0,0,$expiry,$previewHash,$hasher),InvalidArgumentException::class);
        H::rejects(static fn()=>new Binding($id,$submission,3,-1,$expiry,$previewHash,$hasher),InvalidArgumentException::class);
        $raw=file_get_contents(dirname(__DIR__,5).'/fixtures/platform/v1/confirmation/binding-map.json');
        if (!is_string($raw)) { throw new RuntimeException('Required binding map missing'); }
        /** @var array{platform_binding_fields:list<string>} $map */
        $map=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        H::check($map['platform_binding_fields']===array_keys($base->toArray()));
    },
    'confirmation.no-caller-gate'=>static function (): void {
        $api=new ReflectionClass(ConfirmationGateway::class);
        foreach ($api->getMethods() as $method) {
            foreach ($method->getParameters() as $parameter) {
                H::check(!in_array($parameter->getName(),['required','allow','GateDecision','evidence','clock','actor','tenant'],true));
            }
        }
        H::check((new ReflectionClass(Binding::class))->isReadOnly());
    },
];
H::run($cases,'contract-scripted','contract-scripted');
