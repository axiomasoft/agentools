#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Отрицательный адаптер: структурное расхождение с golden. */
$goldenPairs = [];

while (($line = fgets(STDIN)) !== false) {
    $message = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    switch ($message['type'] ?? '') {
        case 'start':
            echo json_encode(
                ['type' => 'ready', 'sdk' => 'fixture/struct-drift', 'spec_rev' => $message['spec_rev'], 'declared_level' => 'L3'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ) . "\n";
            break;
        case 'golden_pair':
            $goldenPairs[$message['vector_id'] ?? ''] = $message;
            break;
        case 'run':
            if (isset($goldenPairs[$message['vector_id']])) {
                $golden = (string) $goldenPairs[$message['vector_id']]['golden'];
                if (str_starts_with($golden, 'sha-256:')) {
                    $canonical = 'sha-256:0000000000000000000000000000000000000000000000000000000000000000';
                } else {
                    $decoded = json_decode($golden, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        $decoded['__struct_drift'] = true;
                    }
                    $canonical = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                echo json_encode(
                    [
                        'type' => 'result',
                        'vector_id' => $message['vector_id'],
                        'outcome' => 'completed',
                        'final_state' => 'completed',
                        'canonical' => $canonical,
                        'canonical_golden' => $golden,
                    ],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                ) . "\n";
                break;
            }
            echo json_encode(
                ['type' => 'result', 'vector_id' => $message['vector_id'], 'outcome' => 'completed', 'final_state' => 'completed'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ) . "\n";
            break;
        case 'stop':
            exit(0);
    }
}
