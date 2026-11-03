<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Core\Models\Qualification;
use AgenToolsPlatform\Core\Models\Routing\{CatalogEntry, RouteRequest};
/** Reads CURRENT owner hashes/material access; never a cached permission grant. */
interface RequirementSource { public function current(RouteRequest $request, CatalogEntry $entry): ?Requirements; }
