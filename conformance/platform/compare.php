<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/vendor/autoload.php';
$input = json_decode((string)stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$result = \AgenTools\Conformance\SemanticParity::compareProfiles($input['expected'],$input['actual']);
echo json_encode($result,JSON_THROW_ON_ERROR).PHP_EOL;
exit(\AgenTools\Conformance\SemanticParity::failed($result)?1:0);
