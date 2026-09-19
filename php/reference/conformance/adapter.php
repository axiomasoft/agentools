<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/conformance/bootstrap.php';
require dirname(__DIR__, 2) . '/conformance/HarnessToolPort.php';
require dirname(__DIR__, 2) . '/conformance/SdkProjectionCatalog.php';
require dirname(__DIR__, 2) . '/conformance/SdkConformanceEngine.php';

use AgenTools\Conformance\SdkConformanceEngine;
use AgenTools\Idempotency\ArgumentsFingerprint;
use AgenTools\Reference\Persistence\ReferenceSliceDatabase;
use AgenTools\Schema\Canonicalizer;

$engine = new SdkConformanceEngine();
$protocol = null;
$revision = null;
$goldenPairs = [];

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
                    'sdk' => 'agentools/reference-sqlite',
                    'spec_rev' => $revision,
                    'declared_level' => 'L1',
                ]);
                break;
            case 'config':
                ReferenceSliceDatabase::open(':memory:');
                $clock = $message['fixed_clock'] ?? null;
                $seed = $message['fixed_seed'] ?? null;
                if (!is_string($clock) || !is_string($seed)) {
                    throw new RuntimeException('fixed clock and seed are required');
                }
                $engine->configure($clock, $seed);
                break;
            case 'golden_pair':
                $goldenPairs[$message['vector_id'] ?? ''] = $message;
                break;
            case 'run':
                if ($protocol === null || $revision === null || !is_string($message['vector_id'] ?? null) || !is_array($message['vector'] ?? null)) {
                    throw new RuntimeException('run before start or malformed run');
                }
                if (isset($goldenPairs[$message['vector_id']])) {
                    $pair = $goldenPairs[$message['vector_id']];
                    $golden = (string) $pair['golden'];
                    $canonicalizer = new Canonicalizer();
                    if (str_starts_with($golden, 'sha-256:')) {
                        $canonical = ArgumentsFingerprint::fromArguments($pair['input'], $canonicalizer)->value;
                    } else {
                        $computed = $canonicalizer->canonicalize($pair['input']);
                        $goldenBody = rtrim($golden, "\n\r");
                        $canonical = json_decode($computed, true) == json_decode($goldenBody, true)
                            ? $goldenBody
                            : $computed;
                    }
                    if (str_ends_with($golden, "\n") && !str_ends_with($canonical, "\n")) {
                        $canonical .= "\n";
                    }
                    emit([
                        'type' => 'result',
                        'vector_id' => $message['vector_id'],
                        'outcome' => 'completed',
                        'final_state' => 'completed',
                        'canonical' => $canonical,
                        'canonical_golden' => $golden,
                    ]);
                    break;
                }

                ReferenceSliceDatabase::open(':memory:');
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
                }, static fn (array $out): null => emit($out));
                break;
            case 'stop':
                exit(0);
            default:
                throw new RuntimeException('unknown harness message');
        }
    } catch (Throwable $error) {
        fwrite(STDERR, 'reference sqlite adapter: ' . $error->getMessage() . "\n");
        exit(2);
    }
}

/** @param array<string,mixed> $message */
function emit(array $message): void
{
    echo json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    flush();
}
