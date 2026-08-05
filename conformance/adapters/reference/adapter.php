<?php

declare(strict_types=1);

require __DIR__ . '/ReferenceEngine.php';
require __DIR__ . '/CompiledReference.php';

use AgenTools\Reference\ReferenceEngine;

/**
 * Внешняя оболочка эталона намеренно владеет только JSONL-протоколом. Семантика
 * получает ровно given/script, поэтому fixtures не могут стать oracle для SUT.
 */
$engine = new ReferenceEngine();
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
                emit(['type' => 'ready', 'sdk' => 'agentools/reference', 'spec_rev' => $revision, 'declared_level' => 'L3']);
                break;
            case 'config':
                $engine->configure($message['fixed_clock'] ?? null, $message['fixed_seed'] ?? null);
                break;
            case 'run':
                if ($protocol === null || $revision === null || !is_string($message['vector_id'] ?? null) || !is_array($message['vector'] ?? null)) {
                    throw new RuntimeException('run before start or malformed run');
                }
                $engine->run($message['vector_id'], $message['vector'], static function (array $out): array {
                    emit($out);
                    $reply = fgets(STDIN);
                    if ($reply === false) {
                        throw new RuntimeException('runner closed probe channel');
                    }
                    $decoded = json_decode($reply, true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($decoded) || ($decoded['type'] ?? null) !== 'probe_result') {
                        throw new RuntimeException('invalid probe response');
                    }
                    return $decoded;
                }, static fn(array $out): null => emit($out));
                break;
            case 'stop':
                exit(0);
            default:
                throw new RuntimeException('unknown harness message');
        }
    } catch (Throwable $error) {
        fwrite(STDERR, 'reference adapter: ' . $error->getMessage() . "\n");
        exit(2);
    }
}

/** @param array<string,mixed> $message */
function emit(array $message): void
{
    echo json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    flush();
}
