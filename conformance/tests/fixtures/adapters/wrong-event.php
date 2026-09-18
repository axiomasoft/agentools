#!/usr/bin/env php
<?php

declare(strict_types=1);

while (($line = fgets(STDIN)) !== false) {
    $message = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    if (($message['type'] ?? '') === 'start') {
        echo json_encode(['type' => 'ready', 'sdk' => 'fixture/wrong-event', 'spec_rev' => $message['spec_rev'], 'declared_level' => 'L3']) . "\n";
    }
    if (($message['type'] ?? '') === 'run') {
        echo json_encode(['type' => 'event', 'vector_id' => $message['vector_id'], 'kind' => 'definitely_wrong']) . "\n";
        echo json_encode(['type' => 'result', 'vector_id' => $message['vector_id'], 'outcome' => 'completed', 'final_state' => 'wrong']) . "\n";
    }
    if (($message['type'] ?? '') === 'stop') {
        exit(0);
    }
}
