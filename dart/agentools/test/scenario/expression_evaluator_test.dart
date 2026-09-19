import 'package:agentools/agentools.dart';
import 'package:test/test.dart';

void main() {
  // SCN-13, SCN-14; E1–E12 are interpreted from an AST without dynamic evaluation.
  test('evaluates the published control points', () {
    final context = {
      'today': '2026-08-03',
      'tenant': 'acme',
      'flag': true,
      'draft': {'status': 'ready', 'items': 3, 'parent': null},
    };
    final steps = {
      'extract': {'text': '…', 'confidence': 0.9, 'ok': true},
      'clarify': {'answered': false},
    };
    final evaluator = ExpressionEvaluator();

    expect(
      evaluator.evaluate(r'$ctx.today == "2026-08-03"', context, steps),
      isTrue,
    );
    expect(
      evaluator.evaluate(r'$steps.extract.confidence == 0.9', context, steps),
      isTrue,
    );
    expect(
      evaluator.evaluate(
        r'(missing($steps.review.done) || $ctx.flag) && !$steps.clarify.answered',
        context,
        steps,
      ),
      isTrue,
    );
    expect(
      evaluator.evaluate(r'$ctx.today == 2026', context, steps),
      isFalse,
    );
    expect(
      evaluator.evaluate(r'missing($ctx.draft.parent)', context, steps),
      isFalse,
    );
  });

  // SCN-15 / E15: host-code-shaped input is rejected by the grammar before evaluation.
  test('rejects host code syntax', () {
    expect(
      () => ExpressionEvaluator().evaluate(
        r'$ctx.today == exec("id")',
        {'today': '2026-08-03'},
      ),
      throwsA(isA<ExpressionException>()),
    );
  });

  // SCN-16: parse depth above the published limit reports expression.max_depth.
  test('names the exceeded depth limit', () {
    try {
      ExpressionEvaluator().evaluate(
        '${'(' * 33}true${')' * 33}',
        {},
      );
      fail('Expected depth limit failure.');
    } on ExpressionException catch (error) {
      expect(error.limitName, 'expression.max_depth');
    }
  });

  // SCN-17: expression length above the published limit reports expression.max_length.
  test('names the exceeded length limit', () {
    final evaluator = ExpressionEvaluator(
      limits: ExpressionLimits(maxLength: 10),
    );
    final longLiteral = 'A' * 11;
    try {
      evaluator.evaluate(
        r'$ctx.flag == "' + longLiteral + '"',
        {'flag': true},
      );
      fail('Expected length limit failure.');
    } on ExpressionException catch (error) {
      expect(error.limitName, 'expression.max_length');
    }
  });

  // SCN-18: projected collection size above the published limit reports expression.max_collection_size.
  test('names the exceeded collection limit', () {
    final evaluator = ExpressionEvaluator(
      limits: ExpressionLimits(maxCollectionSize: 3),
    );
    try {
      evaluator.evaluate(r'$ctx.items == null', {
        'items': [0, 1, 2, 3],
      });
      fail('Expected collection limit failure.');
    } on ExpressionException catch (error) {
      expect(error.limitName, 'expression.max_collection_size');
    }
  });

  // SCN-19: visited AST nodes above the published limit reports expression.max_visited_nodes.
  test('names the exceeded visited nodes limit', () {
    final evaluator = ExpressionEvaluator(
      limits: ExpressionLimits(maxVisitedNodes: 3),
    );
    try {
      evaluator.evaluate('(true && true && true && true)', {});
      fail('Expected visited-node limit failure.');
    } on ExpressionException catch (error) {
      expect(error.limitName, 'expression.max_visited_nodes');
    }
  });
}
