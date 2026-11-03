<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Core\Models\Qualification;
use AgenToolsPlatform\Core\Common\UtcInstant;
/** Current host permission/quota/material/emergency checks, also on pin replay. Throws on unknown/deny. */
interface SelectionGate { public function authorize(Snapshot $snapshot, UtcInstant $now): void; }
