<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Actions;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, CanonicalJson};

final readonly class ActionSubmission
{
    public string $inputJson;

    public function __construct(public ActionRequest $request, string $inputJson, CanonicalHasher $hasher, CanonicalJson $json)
    {
        $this->inputJson = $json->canonicalizeJson($inputJson);
        $input = json_decode($this->inputJson, false, 512, JSON_THROW_ON_ERROR);
        $request->inputHash->assertSame($hasher->hash($request->context->identity->tenant, 'action-input', $input));
    }
}
