#!/usr/bin/env php
<?php

declare(strict_types=1);

while (($line = fgets(STDIN)) !== false) {
    $message = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    if (($message['type'] ?? '') === 'start') {
        echo json_encode(['type' => 'ready', 'sdk' => 'fixture/result-owned', 'spec_rev' => $message['spec_rev'], 'declared_level' => 'L1']) . "\n";
    }
    if (($message['type'] ?? '') === 'run') {
        $field = getenv('FORBIDDEN_FIELD') ?: 'observations';
        echo json_encode(['type' => 'result', 'vector_id' => $message['vector_id'], 'outcome' => 'completed', 'final_state' => 'completed', $field => []]) . "\n";
    }
}
