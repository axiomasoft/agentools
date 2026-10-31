<?php

declare(strict_types=1);
require_once dirname(__DIR__,2) . '/php/conformance/semantic_profile.php';
echo json_encode(agentools_semantic_profile(),JSON_THROW_ON_ERROR).PHP_EOL;
