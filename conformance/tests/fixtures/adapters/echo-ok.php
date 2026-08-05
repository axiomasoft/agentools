#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Фикстурный адаптер повторяет ожидания: он проверяет механику раннера, не семантику SDK. */
while (($line = fgets(STDIN)) !== false) {
    $message = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    switch ($message['type'] ?? '') {
        case 'start':
            echo json_encode(['type' => 'ready', 'sdk' => 'fixture/echo-ok', 'spec_rev' => $message['spec_rev'], 'declared_level' => 'L3'], JSON_UNESCAPED_UNICODE) . "\n";
            break;
        case 'run':
            $vector = $message['vector'];
            foreach ($vector['expect']['events'] ?? [] as $event) {
                $actual = [];
                foreach ($event as $key => $value) {
                    if (!str_starts_with((string) $key, '!')) {
                        $actual[$key] = $value;
                    }
                }
                echo json_encode(['type' => 'event', 'vector_id' => $message['vector_id']] + $actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            }
            echo json_encode(['type' => 'result', 'vector_id' => $message['vector_id'], 'outcome' => 'completed', 'final_state' => $vector['expect']['final_state'] ?? null, 'tool_calls' => $vector['expect']['tool_calls'] ?? [], 'error' => $vector['expect']['error'] ?? null], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            break;
        case 'stop':
            exit(0);
    }
}
