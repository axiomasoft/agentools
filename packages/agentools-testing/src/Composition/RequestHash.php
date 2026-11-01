<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Testing\Composition;

use AgenToolsPlatform\Core\Common\{CanonicalHasher,PayloadHash};
use AgenToolsPlatform\Core\Models\ModelRequest;

final class RequestHash
{
    public static function model(ModelRequest $request,CanonicalHasher $hasher): PayloadHash
    {
        $data=get_object_vars($request);unset($data['cancellation']);
        return $hasher->hash($request->invocationId->tenant,'model-request',json_decode(json_encode($data,JSON_THROW_ON_ERROR),false,128,JSON_THROW_ON_ERROR));
    }
}
