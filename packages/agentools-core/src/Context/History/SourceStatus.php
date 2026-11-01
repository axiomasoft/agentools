<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context\History;

enum SourceStatus: string { case Available='available'; case Unavailable='unavailable'; case Failed='failed'; case Revoked='revoked'; case Deleted='deleted'; }
