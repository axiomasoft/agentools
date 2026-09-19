<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/conformance/bootstrap.php';

use AgenTools\Reference\Conformance\ProductSliceEngine;
use AgenTools\Reference\Support\JsonGuard;

$engine = new ProductSliceEngine();
$protocol = null;
$revision = null;

while (($line = fgets(STDIN)) !== false) {
    try {
        $message = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($message)) {
            throw new RuntimeException('harness message must be an object');
        }

        switch ($message['type'] ?? null) {
            case 'start':
                $protocol = $message['harness_protocol_version'] ?? null;
                $revision = $message['spec_rev'] ?? null;
                if ($protocol !== '1.1' || $revision !== '2026-08') {
                    throw new RuntimeException('unsupported harness protocol or revision');
                }
                emit([
                    'type' => 'ready',
                    'sdk' => 'agentools/reference-product-slice',
                    'spec_rev' => $revision,
                    'declared_level' => 'L2',
                ]);
                break;
            case 'config':
                $clock = $message['fixed_clock'] ?? null;
                $seed = $message['fixed_seed'] ?? null;
                if (!is_string($clock) || !is_string($seed)) {
                    throw new RuntimeException('fixed clock and seed are required');
                }
                $engine->configure($clock, $seed);
                break;
            case 'run':
                if ($protocol === null || $revision === null || !is_string($message['vector_id'] ?? null) || !is_array($message['vector'] ?? null)) {
                    throw new RuntimeException('run before start or malformed run');
                }
                $vectorMap = JsonGuard::stringKeyedMap($message['vector']);
                $engine->run($message['vector_id'], $vectorMap, probe_forward(...), static fn (array $out): null => emit($out));
                break;
            case 'stop':
                exit(0);
            default:
                throw new RuntimeException('unknown harness message');
        }
    } catch (Throwable $error) {
        fwrite(STDERR, 'reference product slice adapter: ' . $error->getMessage() . "\n");
        exit(2);
    }
}

/**
 * @param array<string, mixed> $out
 *
 * @return array<string, mixed>
 */
function probe_forward(array $out): array
{
    emit($out);
    $reply = fgets(STDIN);
    if ($reply === false) {
        throw new RuntimeException('runner closed probe channel');
    }
    $decoded = json_decode($reply, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || ($decoded['type'] ?? null) !== 'probe_result') {
        throw new RuntimeException('invalid probe response');
    }

    return JsonGuard::stringKeyedMap($decoded);
}

/** @param array<string,mixed> $message */
function emit(array $message): void
{
    echo json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    flush();
}
