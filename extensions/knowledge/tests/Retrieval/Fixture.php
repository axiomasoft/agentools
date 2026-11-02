<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Tests\Retrieval;

use AgenToolsPlatform\Knowledge\{Decision, EmbeddingSpace, EmbeddingTransport, Extraction, GenericValidator,
    Integrity, KnowledgeRepository, Material, MaterialAccessPolicy, Release, Scope, SourceDocument};
use AgenToolsPlatform\Knowledge\Adapters\PlainTextExtractor;
use AgenToolsPlatform\Knowledge\Retrieval\{Cache, Clock, Query, ReleasePin, Reranker, SearchIndex, Service, SourceAvailability};
use AgenToolsPlatform\Knowledge\Retrieval\Adapters\LexicalIndex;

/** Scripted owner/repository harness only; upstream owns PostgreSQL durability evidence. */
final class Repository implements KnowledgeRepository
{
    public array $releases = [];
    public array $invalid = [];
    public int $gets = 0;
    public ?\Closure $onGet = null;
    public function publish(Scope $scope, Release $release, ?Material $previous = null): Release
    {
        $release->assertPublishable();
        if (!$scope->matches($release->material)) { throw new \DomainException('Scope'); }
        $key = Integrity::digest([$scope->tenant,$release->corpus,$release->id]);
        if (isset($this->releases[$key]) && $this->releases[$key]->hash() !== $release->hash()) { throw new \DomainException('Conflict'); }
        $this->releases[$key] = $release;
        if ($previous !== null) { $this->invalid[$previous->key()] = true; }
        return $release;
    }
    public function get(Scope $scope, string $corpus, string $id): ?Release
    {
        ++$this->gets;
        if ($this->onGet !== null) { ($this->onGet)(); }
        $release = $this->releases[Integrity::digest([$scope->tenant,$corpus,$id])] ?? null;
        if ($release !== null && isset($this->invalid[$release->material->key()])) { throw new \DomainException('Invalidated'); }
        return $release;
    }
    public function invalidate(Scope $scope, Material $material, string $reason): void { $this->invalid[$material->key()] = true; }
    public function link(Scope $scope, Release $release, string $kind, string $ref): void { throw new \LogicException('Not used'); }
    public function dependencyUsable(Scope $scope, Release $release, string $kind, string $ref): bool { throw new \LogicException('Not used'); }
}

final class Owner implements MaterialAccessPolicy
{
    public array $decisions = [];
    public array $calls = [];
    public ?\Closure $onCheck = null;
    public function check(Scope $scope, Material $material, string $operation): Decision
    {
        $this->calls[] = [$scope->tenant,$scope->subject,$scope->purpose,$material->key(),$operation];
        if ($this->onCheck !== null) { ($this->onCheck)($operation); }
        return $this->decisions[$material->key()] ?? Decision::Allowed;
    }
}

final class Sources implements SourceAvailability
{
    public array $decisions = [];
    public array $calls = [];
    public function check(Scope $scope, Material $material): Decision
    {
        $this->calls[] = $material->key();
        return $this->decisions[$material->key()] ?? Decision::Allowed;
    }
}

final class Time implements Clock
{
    public int $value = 2000000000;
    public function now(): int { return $this->value; }
}

final class Index implements SearchIndex
{
    public int $calls = 0;
    public array $seen = [];
    public ?array $override = null;
    public ?\Closure $onSearch = null;
    public ?EmbeddingSpace $replacement = null;
    public bool $fail = false;
    public function __construct(public SearchIndex $inner = new LexicalIndex()) {}
    public function space(): ?EmbeddingSpace { return $this->replacement ?? $this->inner->space(); }
    public function search(Query $query, array $releases, ?array $vector = null): array
    {
        ++$this->calls; $this->seen[] = $releases;
        if ($this->fail) { throw new \RuntimeException('sensitive owner payload must not escape'); }
        $result = $this->override ?? $this->inner->search($query, $releases, $vector);
        if ($this->onSearch !== null) { ($this->onSearch)(); }
        return $result;
    }
}

final class Embeddings implements EmbeddingTransport
{
    public int $calls = 0;
    public array $seen = [];
    public array $vectors = [[1.0,0.0,0.0]];
    public ?\Closure $onEmbed = null;
    public function embed(Scope $scope, EmbeddingSpace $space, array $texts): array
    {
        ++$this->calls; $this->seen[] = [$scope->tenant,$space->key(),$texts];
        if ($this->onEmbed !== null) { ($this->onEmbed)(); }
        return $this->vectors;
    }
}

final class Fixture
{
    public Repository $repository;
    public Owner $owner;
    public Sources $sources;
    public Time $time;
    public Index $lexical;
    public Scope $scope;
    public Embeddings $embeddings;
    public function __construct()
    {
        $this->repository = new Repository(); $this->owner = new Owner(); $this->sources = new Sources();
        $this->time = new Time(); $this->lexical = new Index(); $this->embeddings = new Embeddings();
        $this->scope = new Scope('alpha','actor:1','students','education',['read']);
    }
    public static function space(string $model = 'model:a', string $revision = 'r1', string $normalization = 'unit', string $index = 'i1', string $provider = 'provider:a', int $dimensions = 3): EmbeddingSpace
    { return new EmbeddingSpace($provider,$model,$revision,$dimensions,$normalization,$index); }
    public function add(string $id = 'material:1', string $version = 'v1', string $tenant = 'alpha',
        string $text = 'Unrelated introduction. Gravity attracts masses. Unrelated closing paragraph.',
        string $locale = 'en', array $audiences = ['students'], array $scopes = ['read'],
        ?EmbeddingSpace $space = null, array $programs = ['p1'], bool $overlap = false): ReleasePin
    {
        $m = new Material($tenant,$id,$version,'library:'.$id,'policy:'.$id,'license:'.$id,
            'files:'.$id.':'.$version,hash('sha256',$text),$audiences,$scopes,$locale);
        $source = new SourceDocument($m->bytesRef,$text,[$text]);
        $extraction = (new PlainTextExtractor())->extract($m,$source);
        if ($overlap) {
            $a = $extraction->artifacts;
            $a[] = new \AgenToolsPlatform\Knowledge\Artifact('overlap:1','fragment',1,0,strlen($text),$text,
                $m->sourceHash,hash('sha256',$text),hash('sha256',$text),'page:1');
            $extraction = new Extraction('overlap/v1',$a);
        }
        $release = Release::validate('corpus:1','release:'.$id.':'.$version,$m,$source,$extraction,new GenericValidator(),space:$space);
        $scope = new Scope($tenant,'actor:1',$audiences[0],'education',$scopes);
        $this->repository->publish($scope,$release);
        return new ReleasePin($release->corpus,$release->id,$release->hash(),$m,$programs);
    }
    public function query(array $pins, string $text = 'gravity', string $run = 'run:1', ?Scope $scope = null,
        ?EmbeddingSpace $space = null, string $locale = 'en', ?string $program = 'p1',
        int $maxFragments = 3, int $maxBytes = 2048, int $fragmentBytes = 512): Query
    { return new Query($scope ?? $this->scope,$run,$text,$pins,$locale,$program,$space,$maxFragments,$maxBytes,$fragmentBytes); }
    public function service(?Cache $cache = null, ?SearchIndex $semantic = null, ?Reranker $reranker = null): Service
    { return new Service($this->repository,$this->owner,$this->sources,$this->time,$this->lexical,$semantic,
        $semantic === null ? null : $this->embeddings,$reranker,$cache); }
}
