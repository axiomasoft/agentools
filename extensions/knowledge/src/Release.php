<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge;

/** One source-version partition of a corpus. A corpus may assign multiple exact releases. */
final readonly class Release
{
    /**
     * @param non-empty-list<string> $pageHashes
     * @param non-empty-list<string> $requiredKinds
     */
    private function __construct(public string $corpus, public string $id, public Material $material,
        public Extraction $extraction, public array $pageHashes, public array $requiredKinds,
        public ?EmbeddingSpace $space, private bool $publicationValidated)
    {
        Integrity::text($corpus); Integrity::text($id);
    }

    public static function validate(string $corpus, string $id, Material $material, SourceDocument $source,
        Extraction $extraction, GenericValidator $generic, ?Validator $additional = null,
        ?EmbeddingSpace $space = null): self
    {
        foreach ([$generic, $additional] as $validator) {
            if ($validator === null) { continue; }
            $result = $validator->validate($material, $source, $extraction);
            if ($result->status !== 'validated') {
                throw new \DomainException('Release validation ' . $result->status . ': ' . implode(',', $result->errors));
            }
        }
        return new self($corpus, $id, $material, $extraction,
            array_map(static fn(string $page): string => hash('sha256', $page), $source->pages), $generic->requiredKinds, $space, true);
    }

    public function assertPublishable(): void
    {
        if (!$this->publicationValidated) { throw new \DomainException('Storage decoding does not authorize publication; validate the source'); }
    }

    /** @return array<string,mixed> */
    public function projection(): array
    {
        return ['format' => 'knowledge-release/v1', 'corpus' => $this->corpus, 'id' => $this->id,
            'material' => get_object_vars($this->material), 'extractor_version' => $this->extraction->extractorVersion,
            'artifacts' => array_map(static fn(Artifact $a): array => get_object_vars($a) + ['validation_status' => 'validated'], $this->extraction->artifacts),
            'page_hashes' => $this->pageHashes, 'required_kinds' => $this->requiredKinds,
            'validation_status' => 'validated', 'validation_revision' => 'generic/v1',
            'space' => $this->space === null ? null : get_object_vars($this->space)];
    }

    public function hash(): string { return Integrity::digest($this->projection()); }
    public function json(): string { return json_encode($this->projection(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION); }

    /** Storage decoding only. The repository verifies the digest against immutable persisted metadata. */
    public static function restore(string $json, string $hash): self
    {
        $data = Wire::object(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
        $m = Wire::object($data['material'] ?? null);
        $material = new Material(Wire::string($m, 'tenant'), Wire::string($m, 'id'), Wire::string($m, 'version'),
            Wire::string($m, 'ownerRef'), Wire::string($m, 'accessPolicyRef'), Wire::string($m, 'licenseRef'),
            Wire::string($m, 'bytesRef'), Wire::string($m, 'sourceHash'), Wire::nonemptyStrings($m['audiences'] ?? null),
            Wire::strings($m['scopes'] ?? null), Wire::string($m, 'locale'));
        $artifacts = [];
        foreach (Wire::objects($data['artifacts'] ?? null) as $a) {
            $parent = ($a['parent'] ?? null) === null ? null : Wire::string($a, 'parent');
            $box = ($a['box'] ?? null) === null ? null : Wire::box($a['box']);
            if (($a['validation_status'] ?? null) !== 'validated') { throw new \UnexpectedValueException('Unvalidated artifact'); }
            $artifacts[] = new Artifact(Wire::string($a, 'id'), Wire::string($a, 'kind'), Wire::integer($a, 'page'),
                Wire::integer($a, 'start'), Wire::integer($a, 'end'), Wire::string($a, 'text'), Wire::string($a, 'sourceHash'),
                Wire::string($a, 'pageHash'), Wire::string($a, 'contentHash'), $parent, $box);
        }
        $space = null;
        if (($data['space'] ?? null) !== null) {
            $s = Wire::object($data['space']);
            $space = new EmbeddingSpace(Wire::string($s, 'provider'), Wire::string($s, 'model'), Wire::string($s, 'revision'),
                Wire::integer($s, 'dimensions'), Wire::string($s, 'normalization'), Wire::string($s, 'indexVersion'));
        }
        $release = new self(Wire::string($data, 'corpus'), Wire::string($data, 'id'), $material,
            new Extraction(Wire::string($data, 'extractor_version'), $artifacts), Wire::nonemptyStrings($data['page_hashes'] ?? null),
            Wire::nonemptyStrings($data['required_kinds'] ?? null), $space, false);
        if (($data['format'] ?? null) !== 'knowledge-release/v1' || ($data['validation_status'] ?? null) !== 'validated'
            || ($data['validation_revision'] ?? null) !== 'generic/v1'
            || !hash_equals($hash, $release->hash()) || !hash_equals($hash, Integrity::digest($data))) {
            throw new \UnexpectedValueException('Release integrity mismatch');
        }
        return $release;
    }
}
