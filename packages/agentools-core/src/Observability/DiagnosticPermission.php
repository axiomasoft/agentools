<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Core\Observability;

enum DiagnosticPermission: string { case Capture='diagnostic_capture';case Read='diagnostic_read';case Purge='diagnostic_purge'; }
