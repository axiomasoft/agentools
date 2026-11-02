<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\Knowledge;

use AgenToolsPlatform\Knowledge\{DocumentSource,Material,SourceDocument,Wire};

/** Only explicitly synthetic local fixture files. This is not an OCR/PDF/Files adapter. */
final readonly class FixtureDocument implements DocumentSource
{
    /** @var array<string,mixed> */
    public array $fixture;
    public function __construct(string $path)
    {
        $json=file_get_contents($path);
        if ($json === false) { throw new \RuntimeException('Synthetic document missing'); }
        $fixture=Wire::object(json_decode($json,true,64,JSON_THROW_ON_ERROR));
        if (($fixture['format'] ?? null)!=='synthetic-knowledge-fixture/v1' || ($fixture['synthetic'] ?? null)!==true
            || ($fixture['personal_data'] ?? null)!==false) { throw new \DomainException('Explicit synthetic fixture required'); }
        $this->fixture=$fixture;
    }
    /** @return non-empty-list<string> */
    public function pages(string $version): array
    {
        $versions=Wire::object($this->fixture['versions'] ?? null);$entry=Wire::object($versions[$version] ?? null);
        return Wire::nonemptyStrings($entry['pages'] ?? null);
    }
    public function material(string $tenant, string $version): Material
    {
        $id=Wire::string($this->fixture,'id');$text=implode("\n\f\n",$this->pages($version));
        return new Material($tenant,'synthetic:'.$id,$version,'host:'.$id,'host:policy','host:fixture-license',
            'synthetic-files:'.$id.':'.$version,hash('sha256',$text),[Wire::string($this->fixture,'audience')],['knowledge.read'],Wire::string($this->fixture,'locale'));
    }
    public function load(Material $material): SourceDocument
    {
        $expected=$this->material($material->tenant,$material->version);
        if ($material->sourceHash !== $expected->sourceHash || $material->bytesRef !== $expected->bytesRef || $material->id !== $expected->id) {
            throw new \DomainException('Exact synthetic source binding required');
        }
        $pages=$this->pages($material->version);
        return new SourceDocument($material->bytesRef,implode("\n\f\n",$pages),$pages);
    }
}
