<?php
declare(strict_types=1);

namespace Bakeoff\Manual;

final class P08NestedDepth
{
    /** @param array<string,string> $l6Attrs ключи должны матчить ^attr_[a-z0-9]+$ (patternProperties) */
    public function __construct(public readonly array $l6Attrs)
    {
        foreach (array_keys($l6Attrs) as $key) {
            if (!preg_match('/^attr_[a-z0-9]+$/', $key)) {
                throw new \InvalidArgumentException("ключ '$key' не матчит patternProperties l6");
            }
        }
    }

    public static function fromArray(array $data): self
    {
        $l6 = $data['l1']['l2']['l3']['l4']['l5']['l6'] ?? [];
        return new self($l6);
    }

    public function toArray(): array
    {
        return ['l1' => ['l2' => ['l3' => ['l4' => ['l5' => ['l6' => $this->l6Attrs]]]]]];
    }
}
