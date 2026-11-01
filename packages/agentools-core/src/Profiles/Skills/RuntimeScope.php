<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Skills;

use AgenToolsPlatform\Core\Common\PayloadHash;
use AgenToolsPlatform\Core\Profiles\{BundleManifest, DefinitionRef, ResourceKind, ResourceRef};
use AgenToolsPlatform\Core\Profiles\Composition\{CompiledComposition, LayerRole, OperationalOverrides};

/** Authenticated host inputs, never an authority grant or a replacement for P7.1 assertPin. */
final readonly class RuntimeScope
{
    /**
     * @param list<DefinitionRef> $suiteVersions
     */
    public function __construct(public BundleManifest $bundle, public CompiledComposition $pinned,
        public OperationalOverrides $host, public OperationalOverrides $session, public OperationalOverrides $live,
        public ResourceRef $deployment, public ResourceRef $model, public array $suiteVersions, public PayloadHash $corpusHash)
    {
        if ($deployment->kind !== ResourceKind::Deployment || $model->kind !== ResourceKind::ModelRevision
            || $suiteVersions === [] || count($suiteVersions) > 16) { throw new \InvalidArgumentException('Exact deployment/model/suite tuple required'); }
        $tenant = $bundle->bundle->ref->tenant; $tenant->assertSame($pinned->input()->tenant);
        $deployment->owner->assertVisible($tenant); $model->owner->assertVisible($tenant);
        $installed=$pinned->input()->deployments[$deployment->version->id] ?? null;
        if ($installed === null || $installed->ref->toArray() !== $deployment->toArray()) {
            throw new \AgenToolsPlatform\Core\Profiles\Skills\SelectionFailure('DEPLOYMENT_PIN_MISMATCH');
        }
        if ($pinned->compiled->effectiveJson !== $bundle->compiled->effectiveJson || $pinned->compiled->restrictionsJson !== $bundle->compiled->restrictionsJson) {
            throw new \AgenToolsPlatform\Core\Profiles\Skills\SelectionFailure('PINNED_OUTPUT_MISMATCH');
        }
        foreach ($pinned->input()->layers as $layer) {
            $expected = match ($layer->role) { LayerRole::Definition=>$bundle->definition, LayerRole::Profile=>$bundle->profile, default=>null };
            if ($expected !== null && $expected->toArray() !== $layer->dependency->ref->toArray()) { throw new \AgenToolsPlatform\Core\Profiles\Skills\SelectionFailure('PINNED_ROOT_MISMATCH'); }
        }
        $seen = [];
        foreach ($suiteVersions as $suite) { if (isset($seen[$suite->id])) { throw new \InvalidArgumentException('Distinct versioned suites required'); } $seen[$suite->id]=true; }
    }
    /**
     * @return array<string, mixed>
     */
    public function identity(): array
    {
        return ['bundle'=>$this->bundle->bundle->toArray(),'deployment'=>$this->deployment->toArray(),'model_revision'=>$this->model->toArray(),
            'suites'=>array_map(static fn (DefinitionRef $s): array => $s->toArray(),$this->suiteVersions),'corpus_hash'=>$this->corpusHash->value,
            'host'=>$this->host->dependency->toArray(),'session'=>$this->session->dependency->toArray(),'live'=>$this->live->dependency->toArray()];
    }
}
