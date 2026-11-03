<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Core\Observability;

enum Outcome: string { case Allowed='allowed'; case Denied='denied'; case Waiting='waiting'; case Unknown='unknown'; case Succeeded='succeeded'; case Failed='failed'; case Interrupted='interrupted'; }
