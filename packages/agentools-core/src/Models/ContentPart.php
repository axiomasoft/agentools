<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models;

use AgenToolsPlatform\Core\Common\Ref;

final readonly class ContentPart
{
    private function __construct(public ContentKind $kind, public ?string $text,
        public ?Ref $ref, public ?string $mediaType) {}

    public static function text(string $text): self
    {
        return new ContentPart(ContentKind::Text, $text, null, null);
    }

    public static function reference(Ref $ref, ContentKind $kind = ContentKind::Ref, ?string $mediaType = null): self
    {
        if ($kind === ContentKind::Text || ($kind !== ContentKind::Ref && ($mediaType === null || $mediaType === ''))) {
            throw new \InvalidArgumentException('Media parts need a type and a scoped reference');
        }
        return new ContentPart($kind, null, $ref, $mediaType);
    }

    public function capability(): Capability
    {
        return match ($this->kind) {
            ContentKind::Text => Capability::Text, ContentKind::Ref => Capability::References,
            ContentKind::Image => Capability::Vision, ContentKind::Audio => Capability::Audio,
        };
    }
}
