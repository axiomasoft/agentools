<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Security;

enum DisclosureMode: string { case Buffered='buffered';case CheckedSegments='checked_segments';case Live='live'; }
