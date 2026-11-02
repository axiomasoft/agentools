<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge;

/** Optional synchronous ingest service; never starts another assistant/effect loop. */
final readonly class Pipeline
{
    public function __construct(private DocumentSource $source, private Extractor $extractor,
        private GenericValidator $generic, private MaterialAccessPolicy $access,
        private KnowledgeRepository $repository, private ?Validator $additional = null) {}

    public function ingest(Scope $scope, string $corpus, string $id, Material $material,
        ?EmbeddingSpace $space = null, ?Material $previous = null): Release
    {
        $this->authorize($scope, $material, 'extract');
        $source = $this->source->load($material);
        $extraction = $this->extractor->extract($material, $source);
        $release = Release::validate($corpus, $id, $material, $source, $extraction, $this->generic, $this->additional, $space);
        $this->authorize($scope, $material, 'publish');
        return $this->repository->publish($scope, $release, $previous);
    }

    private function authorize(Scope $scope, Material $material, string $operation): void
    {
        if (!$scope->matches($material) || $this->access->check($scope, $material, $operation) !== Decision::Allowed) {
            throw new \DomainException('Current owner access denied or unknown');
        }
    }
}
