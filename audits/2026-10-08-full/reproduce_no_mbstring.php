<?php
// Run with php -n; loads only AgenTools source, without development polyfills.
spl_autoload_register(function (string $class): void {
    $prefix = 'AgenTools\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__, 2) . '/php/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});
foreach ([
    'expression' => fn() => (new AgenTools\Scenario\ExpressionEvaluator())->evaluate('true', []),
    'canonicalizer' => fn() => (new AgenTools\Schema\Canonicalizer())->canonicalize(['b' => 1, 'a' => 2]),
] as $name => $run) {
    try { $result = $run(); }
    catch (Throwable $e) { $result = $e::class . ': ' . ($e->getPrevious()?->getMessage() ?? $e->getMessage()); }
    echo json_encode(['probe' => $name, 'actual' => $result]), "\n";
}
