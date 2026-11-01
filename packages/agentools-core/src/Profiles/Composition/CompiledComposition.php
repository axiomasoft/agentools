<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Composition;

use AgenToolsPlatform\Core\Profiles\{CompiledProfile, Dependency};

/** Only filtered output is serializable; input registry schemas never leak to a model projection. */
final readonly class CompiledComposition implements \JsonSerializable
{
    /**
     * @param list<Dependency> $materials
     */
    public function __construct(private CompilationInput $input, public CompiledProfile $compiled, public array $materials) {}
    public function input(): CompilationInput { return $this->input; }
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array { return $this->compiled->toArray(); }
    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array { return $this->toArray(); }
}
