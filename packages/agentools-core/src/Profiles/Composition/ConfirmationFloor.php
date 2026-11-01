<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Composition;

/** Internal policy order only; does not extend the dated confirmation wire protocol. */
enum ConfirmationFloor: int
{
    case None = 0;
    case Required = 1;
    case Fresh = 2;
}
