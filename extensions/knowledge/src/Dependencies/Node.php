<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Dependencies;

use AgenToolsPlatform\Knowledge\{Integrity, Material, Release};

/** Reference only: identity excludes hash so substitution is a conflict, never a new node. */
final readonly class Node
{
    public function __construct(public string $tenant, public string $kind, public string $ref,
        public string $version, public string $hash)
    {
        foreach ([$tenant, $ref, $version] as $value) { Integrity::text($value); }
        if (!in_array($kind, ['material','fragment','card','release','bundle','snapshot','cache',
            'message','summary','memory','history_index','context'], true)) {
            throw new \InvalidArgumentException('Known dependency kind required');
        }
        Integrity::hash($hash);
    }
    public function key(): string { return Integrity::digest([$this->tenant,$this->kind,$this->ref,$this->version]); }
    public static function material(Material $material): self
    { return new self($material->tenant,'material',$material->id,$material->version,Integrity::digest($material)); }
    public static function release(Release $release): self
    { return new self($release->material->tenant,'release',Integrity::digest([$release->corpus,$release->id]),'1',$release->hash()); }
}
