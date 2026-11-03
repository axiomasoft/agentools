<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Core\Observability;

enum Stage: string
{
    case Admission='admission'; case Context='context'; case Deployment='deployment'; case Runtime='runtime';
    case Provider='provider'; case Tool='tool'; case Action='action'; case Effect='effect';
    case Disclosure='disclosure'; case Delivery='delivery'; case Recovery='recovery'; case Diagnostics='diagnostics';
}
