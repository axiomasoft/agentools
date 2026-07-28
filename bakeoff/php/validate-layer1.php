<?php
// Прогон валидатора слоя 1 CI (opis/json-schema, пин D15) по паре schema/document — P4.6, T-2.
// Вывод и код возврата — наблюдаемый факт энфорсмента ключевого слова, не документация пакета.
// Использование: php validate-layer1.php <schema.json> <document.json>
require __DIR__ . '/vendor/autoload.php';

use Opis\JsonSchema\Validator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Resolvers\SchemaResolver;
use Opis\JsonSchema\{SchemaLoader, Parsers\SchemaParser};

[, $schemaFile, $dataFile] = $argv;

$schema = json_decode(file_get_contents($schemaFile));
$data = json_decode(file_get_contents($dataFile));

// Кросс-файловые $ref (напр. spec/revisions/2026-08/schema/authoring/audit-event.json →
// lifecycle-event.json) резолвятся относительно $id схемы — регистрируем префикс на каталог
// файла, чтобы относительные ссылки внутри одного каталога резолвились локально, без сети.
$resolver = new SchemaResolver();
if (isset($schema->{'$id'})) {
    $dir = dirname($schema->{'$id'}) . '/';
    $resolver->registerPrefix($dir, dirname($schemaFile));
}
$loader = new SchemaLoader(new SchemaParser(), $resolver, true);
$validator = new Validator($loader);
$result = $validator->validate($data, $schema);

if ($result->isValid()) {
    echo "VALID\n";
    exit(0);
}

$error = $result->error();
echo "INVALID\n";
echo "keyword: " . $error->keyword() . "\n";
$formatted = (new ErrorFormatter())->formatFlat($error);
foreach ($formatted as $message) {
    echo "  - $message\n";
}
exit(1);
