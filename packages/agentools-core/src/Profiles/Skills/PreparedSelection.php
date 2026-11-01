<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Skills;

/** The only model/context projection: selected fragment data and filtered registered tools. */
final readonly class PreparedSelection implements \JsonSerializable
{
    /**
     * @param list<Fragment> $fragments
     */
    public function __construct(public SelectionSnapshot $selection, private array $fragments, private Projection $projection) {}
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['selection'=>$this->selection->jsonSerialize(),'fragments'=>array_map(static fn (Fragment $f): array => $f->data(),$this->fragments),
            'tools'=>$this->projection->toolViews(),'restrictions'=>json_decode($this->projection->composition->compiled->restrictionsJson,false,64,JSON_THROW_ON_ERROR)];
    }
    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array { return $this->toArray(); }
}
