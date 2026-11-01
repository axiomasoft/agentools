<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Security;

enum DeliveryState: string { case Delivered='delivered';case Interrupted='interrupted';case Unknown='unknown'; }
