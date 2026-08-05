<?php

declare(strict_types=1);

namespace AgenTools\Conformance;

use RuntimeException;

final class Runner
{
    public const PROTOCOL_VERSION = '1.0';

    /** @param array<string,mixed> $options */
    public function run(array $options): array
    {
        $vectors = $this->loadVectors($options['vectors'], $options['level'], $options['filter']);
        $report = [
            'spec_rev' => $options['spec_rev'],
            'harness_protocol_version' => self::PROTOCOL_VERSION,
            'adapter' => null,
            'summary' => ['total' => count($vectors), 'pass' => 0, 'fail' => 0],
            'vectors' => [],
        ];

        try {
            $adapter = new AdapterProcess($options['adapter']);
            $adapter->send(['type' => 'start', 'harness_protocol_version' => self::PROTOCOL_VERSION, 'spec_rev' => $options['spec_rev']]);
            $ready = $adapter->receive($options['timeout']);
            $this->requireType($ready, 'ready');
            foreach (['sdk', 'spec_rev', 'declared_level'] as $field) {
                if (!isset($ready[$field])) {
                    throw new ProtocolViolation("ready адаптера не содержит {$field}");
                }
            }
            $report['adapter'] = $ready;
            $adapter->send(['type' => 'config', 'fixed_clock' => '2000-01-01T00:00:00Z', 'fixed_seed' => 'agentools-conformance']);

            foreach ($vectors as $vector) {
                $report['vectors'][] = $this->runVector($adapter, $vector, $options['timeout']);
            }
            $adapter->send(['type' => 'stop']);
            $adapter->stop();
        } catch (SutFailure $e) {
            $detail = 'отказ SUT: ' . $e->getMessage();
            foreach ($vectors as $vector) {
                if (!array_filter($report['vectors'], static fn(array $entry): bool => $entry['id'] === $vector['id'])) {
                    $report['vectors'][] = $this->failure($vector, $detail);
                }
            }
        }

        usort($report['vectors'], static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
        foreach ($report['vectors'] as $entry) {
            $report['summary'][$entry['status']]++;
        }
        return $report;
    }

    /** @return array<string,mixed> */
    private function runVector(AdapterProcess $adapter, array $vector, float $timeout): array
    {
        $adapter->send(['type' => 'run', 'vector_id' => $vector['id'], 'vector' => $vector]);
        $events = [];
        while (true) {
            $message = $adapter->receive($timeout);
            if (($message['type'] ?? null) === 'event') {
                if (($message['vector_id'] ?? null) !== $vector['id']) {
                    throw new ProtocolViolation('event относится к другому vector_id');
                }
                unset($message['type'], $message['vector_id']);
                $events[] = $message;
                continue;
            }
            $this->requireType($message, 'result');
            if (($message['vector_id'] ?? null) !== $vector['id']) {
                throw new ProtocolViolation('result относится к другому vector_id');
            }
            if (($message['outcome'] ?? null) !== 'completed') {
                return $this->failure($vector, 'адаптер вернул outcome ' . ($message['outcome'] ?? 'отсутствует'));
            }
            $mismatches = Matcher::vector(
                $vector['expect'],
                $events,
                ['final_state' => $message['final_state'] ?? null],
                $message['tool_calls'] ?? [],
                $message['error'] ?? null,
            );
            return $this->entry($vector, $mismatches);
        }
    }

    /** @return list<array<string,mixed>> */
    private function loadVectors(string $root, ?string $level, ?string $filter): array
    {
        $base = rtrim($root, '/') . '/vectors';
        if (!is_dir($base)) {
            throw new RuntimeException("нечитаемый корпус behavior-векторов: {$base}");
        }
        $files = glob($base . '/l[123]/*.json') ?: [];
        $vectors = [];
        foreach ($files as $file) {
            try {
                $vector = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new RuntimeException("невалидный JSON вектора {$file}: {$e->getMessage()}");
            }
            if (($vector['vector_class'] ?? null) !== 'behavior') {
                throw new RuntimeException("вектор {$file} не класса behavior");
            }
            if (!isset($vector['id'], $vector['expect']) || !is_array($vector['expect'])) {
                throw new RuntimeException("структурно неожиданный behavior-вектор {$file}");
            }
            if (($level !== null && ($vector['conformance_level'] ?? null) !== $level)
                || ($filter !== null && !str_starts_with($vector['id'], $filter))) {
                continue;
            }
            $vectors[] = $vector;
        }
        usort($vectors, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
        return $vectors;
    }

    /** @return array<string,mixed> */
    private function entry(array $vector, array $mismatches): array
    {
        return [
            'id' => $vector['id'],
            'level' => $vector['conformance_level'],
            'vector_class' => $vector['vector_class'],
            'spec_rule_ids' => $vector['spec_rule_ids'],
            'status' => $mismatches === [] ? 'pass' : 'fail',
            'mismatches' => $mismatches,
        ];
    }

    /** @return array<string,mixed> */
    private function failure(array $vector, string $message): array
    {
        return $this->entry($vector, [$message]);
    }

    private function requireType(array $message, string $expected): void
    {
        if (($message['type'] ?? null) !== $expected) {
            throw new ProtocolViolation("нарушение harness-протокола: ожидалось {$expected}, получено " . ($message['type'] ?? 'отсутствует'));
        }
    }
}
