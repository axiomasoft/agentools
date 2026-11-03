<?php
declare(strict_types=1);
namespace AgenToolsPlatform\Postgres\Cache;
use AgenToolsPlatform\Core\Common\{CanonicalHasher,Ref,UtcInstant};
use AgenToolsPlatform\Core\Models\Cache\{Descriptor,Key,WarmIntent};
use AgenToolsPlatform\Core\Usage\Values;

final readonly class Codec
{
    public function __construct(private CanonicalHasher $hasher) {}
    public function key(string $json): Key { return Key::fromArray(Values::object(json_decode($json,true,flags:JSON_THROW_ON_ERROR)),$this->hasher); }
    public function descriptor(string $json,?string $state=null,?string $lastUsed=null): Descriptor
    {
        $d=Values::object(json_decode($json,true,flags:JSON_THROW_ON_ERROR)); $key=Key::fromArray(Values::object($d['key']),$this->hasher); $t=$key->id->tenant;
        return new Descriptor($key,new Ref($t,Values::text($d['artifact'])),new UtcInstant(Values::text($d['created_at'])),new UtcInstant(Values::text($d['expires_at'])),
            new UtcInstant($lastUsed ?? Values::text($d['last_used'])),new Ref($t,Values::text($d['rights'])),$state ?? Values::text($d['state']));
    }
    public static function descriptorJson(Descriptor $d): string
    {
        return json_encode(['key'=>$d->key->dimensions,'artifact'=>$d->artifact->value,'created_at'=>$d->createdAt->value,'expires_at'=>$d->expiresAt->value,
            'last_used'=>$d->lastUsed->value,'rights'=>$d->rightsEvidence->value,'state'=>$d->state],JSON_THROW_ON_ERROR);
    }
    public function intent(string $json): WarmIntent
    {
        $d=Values::object(json_decode($json,true,flags:JSON_THROW_ON_ERROR)); $key=Key::fromArray(Values::object($d['key']),$this->hasher);
        return new WarmIntent(new Ref($key->id->tenant,Values::text($d['id'])),$key,new \AgenToolsPlatform\Core\Common\PayloadHash(Values::text($d['request_hash'])),
            new UtcInstant(Values::text($d['created_at'])),new UtcInstant(Values::text($d['expires_at'])),Values::integer($d['estimate']),Values::text($d['unknown_usage_json']),Values::text($d['admission_json']));
    }
    public static function intentJson(WarmIntent $i): string
    { return json_encode(['id'=>$i->id->value,'key'=>$i->key->dimensions,'request_hash'=>$i->requestHash->value,'created_at'=>$i->createdAt->value,
        'expires_at'=>$i->expiresAt->value,'estimate'=>$i->estimate,'unknown_usage_json'=>$i->unknownUsageJson,'admission_json'=>$i->admissionJson],JSON_THROW_ON_ERROR); }
}
