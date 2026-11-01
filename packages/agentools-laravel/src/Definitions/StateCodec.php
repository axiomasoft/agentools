<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Definitions;

use AgenToolsPlatform\Core\Common\{InstanceId, PayloadHash, Ref, TenantId};
use AgenToolsPlatform\Core\Profiles\BundleRef;

/** Closed data format. Deserialization never resolves an executable type. */
final class StateCodec
{
    public static function encode(Instance $state): string
    {
        return json_encode(['tenant_id'=>$state->id->tenant->value,'instance_id'=>$state->id->value,'revision'=>$state->revision,
            'definition'=>$state->definition->toArray(),'bundle'=>['id'=>$state->bundle->ref->value,'version'=>$state->bundle->version,'hash'=>$state->bundle->hash->value],
            'draft'=>$state->draft->toArray()],JSON_THROW_ON_ERROR);
    }
    public static function decode(string $json,TenantId $tenant): Instance
    {
        $d=json_decode($json,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($d) || count($d)!==6 || !is_string($d['tenant_id']??null) || $d['tenant_id']!==$tenant->value
            || !is_string($d['instance_id']??null) || !is_int($d['revision']??null) || !is_array($d['definition']??null)
            || !is_array($d['bundle']??null) || !is_array($d['draft']??null)) { throw new \InvalidArgumentException('Invalid instance data'); }
        $bundle=$d['bundle'];
        if (count($bundle)!==3 || !is_string($bundle['id']??null) || !is_int($bundle['version']??null) || !is_string($bundle['hash']??null)) {
            throw new \InvalidArgumentException('Pinned bundle required');
        }
        return new Instance(new InstanceId($tenant,$d['instance_id']),$d['revision'],Reference::fromJson(json_encode($d['definition'],JSON_THROW_ON_ERROR)),
            new BundleRef(new Ref($tenant,$bundle['id']),$bundle['version'],new PayloadHash($bundle['hash'])),
            DraftSettings::fromJson(json_encode((object)$d['draft'],JSON_THROW_ON_ERROR)));
    }
}
