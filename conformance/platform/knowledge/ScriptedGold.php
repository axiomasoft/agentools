<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Conformance\Knowledge;

use AgenToolsPlatform\Examples\Knowledge\DomainGold;
use AgenToolsPlatform\Knowledge\{Extraction,Material,SourceDocument,ValidationResult,Wire};

/** Independent fixture anchors only; not an expert scorer or a universal pedagogical validator. */
final class ScriptedGold implements DomainGold
{
    public int $calls=0;
    public bool $unavailable=false;
    /** @var array<string,mixed> */
    private array $gold;
    public function __construct(string $path, private readonly string $scenario)
    {
        $raw=file_get_contents($path);
        if ($raw===false) { throw new \RuntimeException('Host gold missing'); }
        $this->gold=Wire::object(json_decode($raw,true,64,JSON_THROW_ON_ERROR));
        if (($this->gold['format'] ?? null)!=='host-synthetic-gold/v1' || ($this->gold['expert_baseline'] ?? null)!==false) {
            throw new \DomainException('Explicit fixture gold required');
        }
    }
    public function check(Material $material, SourceDocument $source, Extraction $extraction): ValidationResult
    {
        ++$this->calls;
        if ($this->unavailable) { throw new \RuntimeException('Scripted host scorer outage'); }
        $versions=Wire::object($this->gold[$this->scenario] ?? null);
        if (!isset($versions[$material->version])) { return new ValidationResult('unknown',['gold_version_missing']); }
        foreach (Wire::nonemptyStrings($versions[$material->version]) as $anchor) {
            if (!str_contains(implode("\n",$source->pages),$anchor)) { return new ValidationResult('failed',['domain_formula_or_unit']); }
        }
        return new ValidationResult('validated');
    }
    public function evidenceRef(): string { return 'host:synthetic-gold:'.$this->scenario.':exact-formula-unit/v1'; }
}
