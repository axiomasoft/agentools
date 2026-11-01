<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Common/bootstrap.php';
\AgenToolsPlatform\Tests\Harness::run(\AgenToolsPlatform\Tests\Harness::loadCases(__DIR__ . '/refusal.php'), 'unit', 'unit');
