<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Tests;

use AgenToolsPlatform\Knowledge\{Artifact, Decision, DocumentSource, EmbeddingSpace, Extraction, GenericValidator,
    KnowledgeRepository, Material, MaterialAccessPolicy, Pipeline, Release, Scope, SourceDocument};
use AgenToolsPlatform\Knowledge\Adapters\PlainTextExtractor;

final class Owner implements MaterialAccessPolicy
{
    public ?\PDO $pdo = null;
    public Decision $decision = Decision::Allowed;
    public array $operations = [];
    public function check(Scope $scope, Material $material, string $operation): Decision
    {
        if ($this->pdo?->inTransaction()) { throw new \LogicException('Owner IO inside transaction'); }
        $this->operations[] = $operation;
        return $this->decision;
    }
}

final class Source implements DocumentSource
{
    public int $loads = 0;
    public function __construct(public SourceDocument $document) {}
    public function load(Material $material): SourceDocument { ++$this->loads; return $this->document; }
}

final class Capture implements KnowledgeRepository
{
    public array $published = [];
    public function publish(Scope $scope, Release $release, ?Material $previous = null): Release { $this->published[] = $release; return $release; }
    public function get(Scope $scope, string $corpus, string $id): ?Release { throw new \LogicException('Not used'); }
    public function invalidate(Scope $scope, Material $material, string $reason): void { throw new \LogicException('Not used'); }
    public function link(Scope $scope, Release $release, string $kind, string $ref): void { throw new \LogicException('Not used'); }
    public function dependencyUsable(Scope $scope, Release $release, string $kind, string $ref): bool { throw new \LogicException('Not used'); }
}

final class Fixture
{
    public Scope $scope;
    public Material $material;
    public Source $source;
    public Owner $owner;
    public Capture $capture;
    public function __construct(string $tenant = 'tenant:a', string $version = 'v1', string $text = 'Проверенный текст.')
    {
        $this->scope = new Scope($tenant, 'actor:a', 'students', 'education', ['read', 'ingest']);
        $this->material = new Material($tenant, 'material:algebra', $version, 'library:algebra', 'policy:school',
            'license:school', 'files:algebra:' . $version, hash('sha256', $text), ['students'], ['read'], 'ru');
        $this->source = new Source(new SourceDocument($this->material->bytesRef, $text, [$text]));
        $this->owner = new Owner(); $this->capture = new Capture();
    }
    public function pipeline(?KnowledgeRepository $repository = null): Pipeline
    {
        return new Pipeline($this->source, new PlainTextExtractor(), new GenericValidator(), $this->owner, $repository ?? $this->capture);
    }
    public function extraction(): Extraction { return (new PlainTextExtractor())->extract($this->material, $this->source->document); }
    public function release(string $id = 'release:1', ?EmbeddingSpace $space = null): Release
    {
        return Release::validate('corpus:school', $id, $this->material, $this->source->document, $this->extraction(), new GenericValidator(), space: $space);
    }
    public static function artifact(Artifact $artifact, array $changes): Artifact
    {
        return new Artifact(...array_replace(get_object_vars($artifact), $changes));
    }
}
