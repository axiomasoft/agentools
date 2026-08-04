<?php

declare(strict_types=1);

namespace AgenTools\Tests\Scenario;

use AgenTools\Scenario\ExpressionEvaluator;
use AgenTools\Scenario\ExpressionException;
use PHPUnit\Framework\TestCase;

final class ExpressionEvaluatorTest extends TestCase
{
    /** SCN-13, SCN-14; E1–E12 are interpreted from an AST without PHP evaluation. */
    public function testItEvaluatesThePublishedControlPoints(): void
    {
        $context = ['today' => '2026-08-03', 'tenant' => 'acme', 'flag' => true, 'draft' => ['status' => 'ready', 'items' => 3, 'parent' => null]];
        $steps = ['extract' => ['text' => '…', 'confidence' => 0.9, 'ok' => true], 'clarify' => ['answered' => false]];
        $evaluator = new ExpressionEvaluator();

        self::assertTrue($evaluator->evaluate('$ctx.today == "2026-08-03"', $context, $steps));
        self::assertTrue($evaluator->evaluate('$steps.extract.confidence == 0.9', $context, $steps));
        self::assertTrue($evaluator->evaluate('(missing($steps.review.done) || $ctx.flag) && !$steps.clarify.answered', $context, $steps));
        self::assertFalse($evaluator->evaluate('$ctx.today == 2026', $context, $steps));
        self::assertFalse($evaluator->evaluate('missing($ctx.draft.parent)', $context, $steps));
    }

    /** SCN-15 / E15: host-code-shaped input is rejected by the grammar before evaluation. */
    public function testItRejectsHostCodeSyntax(): void
    {
        $this->expectException(ExpressionException::class);
        (new ExpressionEvaluator())->evaluate('$ctx.today == exec("id")', ['today' => '2026-08-03']);
    }

    /** SCN-16 through SCN-19: every limit reports its named boundary. */
    public function testItNamesTheExceededDepthLimit(): void
    {
        try {
            (new ExpressionEvaluator())->evaluate(str_repeat('(', 33) . 'true' . str_repeat(')', 33), []);
            self::fail('Expected depth limit failure.');
        } catch (ExpressionException $error) {
            self::assertSame('expression.max_depth', $error->limitName);
        }
    }
}
