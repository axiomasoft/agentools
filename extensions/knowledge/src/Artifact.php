<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge;

final readonly class Artifact
{
    /** @param null|array{float,float,float,float} $box normalized x0,y0,x1,y1 */
    public function __construct(public string $id, public string $kind, public int $page,
        public int $start, public int $end, public string $text, public string $sourceHash,
        public string $pageHash, public string $contentHash, public ?string $parent = null,
        public ?array $box = null)
    {
        Integrity::text($id);
        if (!in_array($kind, ['node', 'fragment', 'navigation', 'card'], true)) {
            throw new \InvalidArgumentException('Unknown artifact kind');
        }
        foreach ([$sourceHash, $pageHash, $contentHash] as $hash) { Integrity::hash($hash); }
        if ($box !== null && (!array_is_list($box) || count($box) !== 4)) {
            throw new \InvalidArgumentException('Four box coordinates required');
        }
    }
}
