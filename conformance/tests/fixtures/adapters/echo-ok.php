#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Фикстурный адаптер проверяет механику без доступа к runner-owned oracle. */
while (($line = fgets(STDIN)) !== false) {
    $message = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    switch ($message['type'] ?? '') {
        case 'start':
            echo json_encode(['type' => 'ready', 'sdk' => 'fixture/echo-ok', 'spec_rev' => $message['spec_rev'], 'declared_level' => 'L3'], JSON_UNESCAPED_UNICODE) . "\n";
            break;
        case 'run':
            $vector = $message['vector'];
            foreach (['expect', 'id', 'title', 'vector_class'] as $forbidden) {
                if (array_key_exists($forbidden, $vector)) {
                    exit(19);
                }
            }
            echo json_encode(['type' => 'result', 'vector_id' => $message['vector_id'], 'outcome' => 'completed', 'final_state' => 'completed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            break;
        case 'stop':
            exit(0);
    }
}
