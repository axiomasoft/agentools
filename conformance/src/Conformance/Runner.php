<?php

declare(strict_types=1);

namespace AgenTools\Conformance;

use RuntimeException;

final class Runner
{
    public const PROTOCOL_VERSION = '1.1';
    private const VECTOR_FIELDS = ['spec_rev', 'spec_rule_ids', 'conformance_level', 'given', 'script'];
    private const PROBE_KINDS = [
        'tool_boundary',
        'journal_projection',
        'audit_sink',
        'task_binding',
        'credential_boundary',
        'confirmation_boundary',
    ];

    /** @param array<string,mixed> $options */
    public function run(array $options): array
    {
        $vectors = $this->loadVectors($options['vectors'], $options['level'], $options['filter'], $options['rules'] ?? null);
        foreach ($vectors as $vector) {
            if (($vector['spec_rev'] ?? null) !== $options['spec_rev']) {
                throw new RuntimeException(sprintf(
                    'ревизия прогона %s не совпадает с ревизией вектора %s (%s)',
                    $options['spec_rev'],
                    $vector['spec_rev'] ?? 'не объявлена',
                    $vector['id'] ?? 'без id',
                ));
            }
        }
        $catalog = RuleCatalog::fromFile($options['rules_index'] ?? 'spec/revisions/' . $options['spec_rev'] . '/rules.json');
        $baseline = Baseline::fromFile($options['baseline'] ?? null);
        $report = [
            'spec_rev' => $options['spec_rev'],
            'harness_protocol_version' => self::PROTOCOL_VERSION,
            'selection' => [
                'rules' => $options['rules'] ?? null,
                'selected_count' => count($vectors),
            ],
            'adapter' => null,
            'summary' => ['total' => count($vectors), 'pass' => 0, 'fail' => 0, 'warn' => 0, 'not-testable' => 0, 'baseline-expected' => 0],
            'baseline_errors' => [],
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
            if ($ready['spec_rev'] !== $options['spec_rev']) {
                throw new ProtocolViolation(sprintf(
                    'ready адаптера объявляет ревизию %s, а прогон запрошен для %s',
                    $ready['spec_rev'],
                    $options['spec_rev'],
                ));
            }
            $report['adapter'] = $ready;
            $adapter->send(['type' => 'config', 'fixed_clock' => '2000-01-01T00:00:00Z', 'fixed_seed' => 'agentools-conformance']);

            $report['golden'] = $this->runGolden($adapter, $options['vectors'], $options['timeout']);

            foreach ($vectors as $vector) {
                $report['vectors'][] = $this->runVector($adapter, $vector, $options['timeout'], $catalog);
            }
            $adapter->send(['type' => 'stop']);
            $adapter->stop();
        } catch (SutFailure $e) {
            $detail = 'отказ SUT: ' . $e->getMessage();
            foreach ($vectors as $vector) {
                if (!array_filter($report['vectors'], static fn(array $entry): bool => $entry['id'] === $vector['id'])) {
                    $report['vectors'][] = $this->failure($vector, $detail, $catalog);
                }
            }
        }

        usort($report['vectors'], static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
        foreach ($report['vectors'] as &$entry) {
            if (in_array($entry['status'], [Status::Fail->value, Status::Warn->value], true)
                && $baseline->matches($entry['id'], $entry['mismatches'])) {
                $entry['status'] = Status::BaselineExpected->value;
            }
            $report['summary'][$entry['status']]++;
        }
        unset($entry);
        $report['baseline_errors'] = $baseline->stale($report['vectors']);
        $report['golden_failures'] = array_values(array_filter($report['golden'] ?? [], static fn(array $entry): bool => $entry['status'] !== 'pass'));
        return $report;
    }

    /** @return list<array<string,mixed>> */
    private function runGolden(AdapterProcess $adapter, string $vectorsRoot, float $timeout): array
    {
        $pairs = [];
        foreach (glob(rtrim($vectorsRoot, '/') . '/golden/*/*.input.json') ?: [] as $inputPath) {
            $base = substr($inputPath, 0, -strlen('.input.json'));
            $goldenPath = $base . '.golden.txt';
            if (!is_file($goldenPath)) {
                continue;
            }
            $id = basename(dirname($base)) . '/' . basename($base);
            $input = json_decode((string) file_get_contents($inputPath), true, 512, JSON_THROW_ON_ERROR);
            $golden = (string) file_get_contents($goldenPath);
            $adapter->send(['type' => 'golden_pair', 'vector_id' => $id, 'fixture_version' => '1.0.0', 'input' => $input, 'golden' => $golden]);
            $adapter->send(['type' => 'run', 'vector_id' => $id, 'vector' => ['spec_rev' => '2026-08', 'spec_rule_ids' => ['CFH-38']]]);
            $result = $adapter->receive($timeout);
            $this->requireType($result, 'result');
            $signals = GoldenComparator::compare((string) ($result['canonical'] ?? ''), $golden, (string) ($result['canonical_golden'] ?? ''));
            $pairs[] = ['id' => $id, 'status' => $signals === [] ? 'pass' : 'fail', 'signals' => $signals];
        }
        return $pairs;
    }

    /** @return array<string,mixed> */
    private function runVector(AdapterProcess $adapter, array $vector, float $timeout, RuleCatalog $catalog): array
    {
        $adapter->send([
            'type' => 'run',
            'vector_id' => $vector['id'],
            'vector' => array_intersect_key($vector, array_flip(self::VECTOR_FIELDS)),
        ]);
        $events = [];
        $observations = [];
        $seenProbeIds = [];
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
            if (($message['type'] ?? null) === 'probe_call') {
                $this->handleProbeCall($adapter, $vector, $message, $observations, $seenProbeIds);
                continue;
            }
            $this->requireType($message, 'result');
            if (($message['vector_id'] ?? null) !== $vector['id']) {
                throw new ProtocolViolation('result относится к другому vector_id');
            }
            foreach (['tool_calls', 'observations'] as $forbidden) {
                if (array_key_exists($forbidden, $message)) {
                    throw new ProtocolViolation("result адаптера не может содержать {$forbidden}: факты принадлежат runner-owned probes");
                }
            }
            if (!is_string($message['final_state'] ?? null) || $message['final_state'] === '') {
                throw new ProtocolViolation('result адаптера не содержит нормализованный final_state');
            }
            if (($message['outcome'] ?? null) !== 'completed') {
                if (($message['outcome'] ?? null) === 'not_testable') {
                    $reason = $catalog->notTestableReason($vector['spec_rule_ids']);
                    if ($reason === null || ($message['not_testable_reason'] ?? null) !== $reason) {
                        return $this->failure($vector, 'Not testable должен нести дословную причину rules.json', $catalog);
                    }
                    return $this->entry($vector, [], Status::NotTestable, $reason);
                }
                return $this->failure($vector, 'адаптер вернул outcome ' . ($message['outcome'] ?? 'отсутствует'), $catalog);
            }
            $mismatches = Matcher::vector(
                $vector['expect'],
                $events,
                ['final_state' => $message['final_state'] ?? null],
                $this->toolCallsFromObservations($observations),
                $message['error'] ?? null,
                $observations,
            );
            return $this->entry($vector, $mismatches, $mismatches === [] ? Status::Pass : $catalog->statusForFailure($vector['spec_rule_ids']));
        }
    }

    /** @param list<array<string,mixed>> $observations @param array<string,true> $seenProbeIds */
    private function handleProbeCall(
        AdapterProcess $adapter,
        array $vector,
        array $message,
        array &$observations,
        array &$seenProbeIds,
    ): void {
        if (($message['vector_id'] ?? null) !== $vector['id']) {
            throw new ProtocolViolation('probe_call относится к другому vector_id');
        }
        $probeId = $message['probe_id'] ?? null;
        $kind = $message['probe_kind'] ?? null;
        $operation = $message['operation'] ?? null;
        if (!is_string($probeId) || $probeId === '') {
            throw new ProtocolViolation('probe_call не содержит probe_id');
        }
        if (isset($seenProbeIds[$probeId])) {
            throw new ProtocolViolation("повторный probe_id {$probeId}");
        }
        if (!is_string($kind) || !in_array($kind, self::PROBE_KINDS, true)) {
            throw new ProtocolViolation('probe_call содержит неизвестный probe_kind');
        }
        if (!is_array($operation) || array_is_list($operation)) {
            throw new ProtocolViolation('probe_call.operation должен быть JSON object');
        }
        $seenProbeIds[$probeId] = true;
        $observations[] = ['kind' => $kind, 'probe_id' => $probeId] + $operation;
        $adapter->send([
            'type' => 'probe_result',
            'vector_id' => $vector['id'],
            'probe_id' => $probeId,
            'probe_result' => $this->probeResult($kind, $operation, $vector),
        ]);
    }

    /** @return array<string,mixed> */
    private function probeResult(string $kind, array $operation, array $vector): array
    {
        $driverState = is_array($vector['given']['driver_state'] ?? null) ? $vector['given']['driver_state'] : [];
        if ($kind === 'tool_boundary') {
            $tool = $operation['tool'] ?? null;
            $results = is_array($driverState['tool_results'] ?? null) ? $driverState['tool_results'] : [];
            foreach ($vector['script'] ?? [] as $step) {
                if (is_array($step) && is_array($step['tool_result'] ?? null)) {
                    $results[] = $step['tool_result'];
                }
            }
            foreach ($results as $result) {
                if (is_array($result) && ($result['tool'] ?? null) === $tool) {
                    return ['tool_result' => $result];
                }
            }
            return ['tool_result' => null];
        }

        return match ($kind) {
            'journal_projection' => ['journal' => $driverState['journal'] ?? []],
            'task_binding' => ['task_channel' => $driverState['task_channel'] ?? []],
            'credential_boundary' => ['credentials' => $driverState['credentials'] ?? []],
            'confirmation_boundary' => ['confirmation' => $driverState['confirmation'] ?? null],
            default => ['accepted' => true],
        };
    }

    /** @param list<array<string,mixed>> $observations @return list<array<string,mixed>> */
    private function toolCallsFromObservations(array $observations): array
    {
        $calls = [];
        foreach ($observations as $observation) {
            if (($observation['kind'] ?? null) !== 'tool_boundary') {
                continue;
            }
            $call = array_intersect_key($observation, array_flip(['tool', 'phase', 'args']));
            if (isset($call['tool'], $call['phase'])) {
                $calls[] = $call;
            }
        }
        return $calls;
    }

    /** @return list<array<string,mixed>> */
    private function loadVectors(string $root, ?string $level, ?string $filter, ?array $rules): array
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
                || ($filter !== null && !str_starts_with($vector['id'], $filter))
                || ($rules !== null && !self::matchesRuleSelectors($vector['spec_rule_ids'] ?? null, $rules))) {
                continue;
            }
            $vectors[] = $vector;
        }
        if ($rules !== null && $vectors === []) {
            throw new RuntimeException('селектор --rules не выбрал ни одного behavior-вектора');
        }
        usort($vectors, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
        return $vectors;
    }

    /** @return null|list<string> */
    public static function normalizeRuleSelectors(?string $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        $selectors = array_map('trim', explode(',', $raw));
        if ($selectors === [] || in_array('', $selectors, true)) {
            throw new \InvalidArgumentException('--rules содержит пустой selector');
        }
        foreach ($selectors as $selector) {
            if (preg_match('/^[A-Z][A-Z0-9]*-(?:\*|[1-9][0-9]*)$/D', $selector) !== 1) {
                throw new \InvalidArgumentException("невалидный selector --rules: {$selector}");
            }
        }
        $selectors = array_values(array_unique($selectors));
        sort($selectors, SORT_STRING);
        return $selectors;
    }

    /** @param mixed $ruleIds @param list<string> $selectors */
    public static function matchesRuleSelectors(mixed $ruleIds, array $selectors): bool
    {
        if (!is_array($ruleIds) || $ruleIds === []) {
            throw new RuntimeException('behavior-вектор не содержит непустой spec_rule_ids');
        }
        foreach ($selectors as $selector) {
            $prefix = str_ends_with($selector, '*') ? substr($selector, 0, -1) : null;
            foreach ($ruleIds as $ruleId) {
                if (!is_string($ruleId)) {
                    throw new RuntimeException('behavior-вектор содержит нестроковый spec_rule_id');
                }
                if (($prefix === null && $ruleId === $selector)
                    || ($prefix !== null && str_starts_with($ruleId, $prefix))) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return array<string,mixed> */
    private function entry(array $vector, array $mismatches, Status $status, ?string $reason = null): array
    {
        return [
            'id' => $vector['id'],
            'level' => $vector['conformance_level'],
            'vector_class' => $vector['vector_class'],
            'spec_rule_ids' => $vector['spec_rule_ids'],
            'status' => $status->value,
            'mismatches' => $mismatches,
        ] + ($reason === null ? [] : ['not_testable_reason' => $reason]);
    }

    /** @return array<string,mixed> */
    private function failure(array $vector, string $message, RuleCatalog $catalog): array
    {
        return $this->entry($vector, [$message], $catalog->statusForFailure($vector['spec_rule_ids']));
    }

    private function requireType(array $message, string $expected): void
    {
        if (($message['type'] ?? null) !== $expected) {
            throw new ProtocolViolation("нарушение harness-протокола: ожидалось {$expected}, получено " . ($message['type'] ?? 'отсутствует'));
        }
    }
}
