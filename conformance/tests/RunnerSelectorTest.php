<?php

declare(strict_types=1);

namespace AgenTools\Conformance\Tests;

use AgenTools\Conformance\Runner;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RunnerSelectorTest extends TestCase
{
    private const CORE = 'EXT-*,TLP-*,ALC-*,CNF-*,CLR-*,IDE-*,CFH-48,CFH-49';
    private const EXTENSION = 'SCN-*,LOP-*,AOB-*,SEC-*';

    public function testSelectorsAreNormalized(): void
    {
        self::assertSame(['CFH-48', 'EXT-*'], Runner::normalizeRuleSelectors(' EXT-*,CFH-48,EXT-* '));
    }

    #[DataProvider('invalidSelectors')]
    public function testInvalidSelectorsFailClosed(string $selector): void
    {
        $this->expectException(InvalidArgumentException::class);
        Runner::normalizeRuleSelectors($selector);
    }

    public static function invalidSelectors(): array
    {
        return [[''], ['EXT'], ['ext-*'], ['EXT-0'], ['EXT-1*'], ['EXT-*,,CLR-*']];
    }

    public function testFrozenCorpusPartitionIsDisjointAndComplete(): void
    {
        $vectors = $this->behaviorVectors();
        $core = $this->idsMatching($vectors, self::CORE);
        $extension = $this->idsMatching($vectors, self::EXTENSION);

        self::assertCount(213, $vectors);
        self::assertCount(121, $core);
        self::assertCount(92, $extension);
        self::assertSame([], array_values(array_intersect($core, $extension)));
        self::assertCount(213, array_unique(array_merge($core, $extension)));

        $grease = array_values(array_filter(array_keys($vectors), static fn(string $id): bool => str_starts_with($id, 'grease-')));
        self::assertNotSame([], $grease);
        self::assertSame([], array_values(array_diff($grease, $core)));
    }

    public function testValidZeroMatchSelectorSelectsNothing(): void
    {
        self::assertSame([], $this->idsMatching($this->behaviorVectors(), 'ZZZ-*'));
    }

    public function testRunnerRejectsZeroMatchSelectorBeforeStartingAdapter(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('не выбрал ни одного');
        (new Runner())->run([
            'adapter' => ['false'],
            'vectors' => dirname(__DIR__, 2) . '/fixtures/2026-08',
            'spec_rev' => '2026-08',
            'level' => null,
            'filter' => null,
            'rules' => Runner::normalizeRuleSelectors('ZZZ-*'),
            'timeout' => 0.1,
        ]);
    }

    /** @return array<string,array<string,mixed>> */
    private function behaviorVectors(): array
    {
        $root = dirname(__DIR__, 2) . '/fixtures/2026-08/vectors/l[123]/*.json';
        $vectors = [];
        foreach (glob($root) ?: [] as $path) {
            $vector = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (($vector['vector_class'] ?? null) === 'behavior') {
                $vectors[$vector['id']] = $vector;
            }
        }
        ksort($vectors, SORT_STRING);
        return $vectors;
    }

    /** @param array<string,array<string,mixed>> $vectors @return list<string> */
    private function idsMatching(array $vectors, string $raw): array
    {
        $selectors = Runner::normalizeRuleSelectors($raw);
        $ids = [];
        foreach ($vectors as $id => $vector) {
            if (Runner::matchesRuleSelectors($vector['spec_rule_ids'] ?? null, $selectors ?? [])) {
                $ids[] = $id;
            }
        }
        sort($ids, SORT_STRING);
        return $ids;
    }
}
