<?php

declare(strict_types=1);

namespace AgenTools\Tests\Adversarial;

use AgenTools\Ops\BaggageLimitException;
use AgenTools\Ops\BaggageRefs;
use AgenTools\Scenario\ExpressionEvaluator;
use AgenTools\Scenario\ExpressionException;
use AgenTools\Schema\Canonicalizer;
use PHPUnit\Framework\TestCase;

final class AdversarialCorpusTest extends TestCase
{
    public function testCorpusCasesMatchPhpEval(): void
    {
        $root = dirname(__DIR__, 3);
        $path = $root . '/fixtures/hardening/adversarial/corpus/v1/cases.jsonl';
        self::assertFileExists($path);
        $lines = file($path);
        self::assertNotFalse($lines);
        $canonicalizer = new Canonicalizer();
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            $request = $decoded['request'] ?? null;
            $expect = $decoded['expect'] ?? null;
            self::assertIsArray($request);
            self::assertIsArray($expect);
            if (($request['op'] ?? '') !== 'jcs' || ($expect['class'] ?? '') !== 'accept') {
                continue;
            }
            $json = $request['json'] ?? null;
            self::assertIsString($json);
            $canonical = $canonicalizer->canonicalizeJson($json);
            if (!isset($expect['bytes'])) {
                self::assertSame($canonical, $canonicalizer->canonicalizeJson($canonical));
                continue;
            }
            self::assertIsString($expect['bytes']);
            self::assertSame($expect['bytes'], $canonical);
        }
    }

    public function testDepthLimitPlusOneIsRed(): void
    {
        try {
            (new ExpressionEvaluator())->evaluate(str_repeat('(', 33) . 'true' . str_repeat(')', 33), []);
            self::fail('expected depth overflow');
        } catch (ExpressionException $exception) {
            self::assertSame('expression.max_depth', $exception->limitName);
        }
    }

    public function testBaggageLimitPlusOneIsRed(): void
    {
        $baggage = new BaggageRefs();
        for ($i = 0; $i < 64; ++$i) {
            $baggage = $baggage->add('id-' . $i);
        }
        try {
            $baggage->add('id-64');
            self::fail('expected baggage overflow');
        } catch (BaggageLimitException $exception) {
            self::assertNotNull($exception->result->error);
        }
    }
}
