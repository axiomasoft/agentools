<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models\Routing;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, PayloadHash};

final class QualificationTuple
{
    public static function hash(RouteRequest $request, CatalogEntry $entry, CanonicalHasher $hasher): PayloadHash
    {
        $suites = $request->suiteVersions; ksort($suites);
        return $hasher->hash($request->subject->tenant, 'routing-qualification', [
            'deployment' => $entry->deployment->ref->value, 'provider' => $entry->deployment->provider,
            'account' => $entry->deployment->account, 'region' => $entry->deployment->region,
            'model_revision' => $entry->deployment->model, 'catalog_hash' => $entry->hash->value,
            'profile_bundle' => $request->bundle->value, 'task' => $request->task, 'purpose' => $request->purpose,
            'suite_versions' => $suites, 'prompt_hash' => $request->promptHash->value,
            'skills_hash' => $request->skillsHash->value, 'tool_schema_hash' => $request->toolsHash->value,
            'material_corpus_hash' => $request->corpusHash->value,
        ]);
    }
}
