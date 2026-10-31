<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Models;

final readonly class VisionOption implements \AgenToolsPlatform\Core\Models\ModelOption
{
    public function __construct(public VisionDetail $detail) {}
    public function capability(): Capability { return Capability::Vision; }
}
