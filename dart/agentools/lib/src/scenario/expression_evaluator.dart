import 'expression_ast.dart';
import 'expression_exception.dart';
import 'expression_limits.dart';
import 'expression_parser.dart';

/// SCN-14–SCN-19. Walks a parsed AST without seeing the original expression string.
final class ExpressionEvaluator {
  ExpressionEvaluator({
    ExpressionLimits? limits,
    ExpressionParser? parser,
  })  : _limits = limits ?? ExpressionLimits(),
        _parser = parser ?? ExpressionParser(limits: limits);

  final ExpressionLimits _limits;
  final ExpressionParser _parser;

  Object? evaluate(
    String expression,
    Map<String, dynamic> context, [
    Map<String, Map<String, dynamic>> steps = const {},
  ]) {
    _visited = 0;
    final tree = _parser.parse(expression);
    return _walk(tree, context, steps);
  }

  Object? _walk(
    ExpressionNode node,
    Map<String, dynamic> context,
    Map<String, Map<String, dynamic>> steps,
  ) {
    if (++_visited > _limits.maxVisitedNodes) {
      throw ExpressionException.limit(
        'expression.max_visited_nodes',
        _limits.maxVisitedNodes,
      );
    }

    return switch (node) {
      LiteralNode(value: final value) => value,
      ReferenceNode(value: final reference) =>
        _resolve(reference, context, steps, allowMissing: false),
      MissingNode(reference: final reference) =>
        !_exists(reference, context, steps),
      NotNode(operand: final operand) =>
        !_boolean(_walk(operand, context, steps)),
      BinaryNode(
        operator: final operator,
        left: final left,
        right: final right,
      ) =>
        _binary(operator, left, right, context, steps),
    };
  }

  Object? _binary(
    String operator,
    ExpressionNode left,
    ExpressionNode right,
    Map<String, dynamic> context,
    Map<String, Map<String, dynamic>> steps,
  ) {
    final leftValue = _walk(left, context, steps);
    Object? rightValue() => _walk(right, context, steps);
    return switch (operator) {
      '&&' => _boolean(leftValue) ? _boolean(rightValue()) : false,
      '||' => _boolean(leftValue) ? true : _boolean(rightValue()),
      '==' => leftValue == rightValue(),
      '!=' => leftValue != rightValue(),
      _ => throw StateError('Unknown expression operator.'),
    };
  }

  bool _boolean(Object? value) {
    if (value is! bool) {
      throw ExpressionException(
        null,
        'Logical operators accept only boolean operands.',
      );
    }
    return value;
  }

  Object? _resolve(
    String reference,
    Map<String, dynamic> context,
    Map<String, Map<String, dynamic>> steps, {
    required bool allowMissing,
  }) {
    final parts = reference.substring(1).split('.');
    final root = parts.removeAt(0);
    Object? value;
    if (root == 'ctx') {
      value = context;
    } else {
      final stepId = parts.isEmpty ? null : parts.removeAt(0);
      value = stepId == null ? null : steps[stepId];
    }
    for (final part in parts) {
      if (value is! Map || !value.containsKey(part)) {
        if (allowMissing) {
          return null;
        }
        throw ExpressionException(null, 'Missing path $reference.');
      }
      value = value[part];
    }
    if (value is List && value.length > _limits.maxCollectionSize) {
      throw ExpressionException.limit(
        'expression.max_collection_size',
        _limits.maxCollectionSize,
      );
    }
    if (value is Map && value.length > _limits.maxCollectionSize) {
      throw ExpressionException.limit(
        'expression.max_collection_size',
        _limits.maxCollectionSize,
      );
    }
    return value;
  }

  bool _exists(
    String reference,
    Map<String, dynamic> context,
    Map<String, Map<String, dynamic>> steps,
  ) {
    final parts = reference.substring(1).split('.');
    final root = parts.removeAt(0);
    Object? value;
    if (root == 'ctx') {
      value = context;
    } else {
      final stepId = parts.isEmpty ? null : parts.removeAt(0);
      if (stepId == null || !steps.containsKey(stepId)) {
        return false;
      }
      value = steps[stepId];
    }
    for (final part in parts) {
      if (value is! Map || !value.containsKey(part)) {
        return false;
      }
      value = value[part];
    }
    return true;
  }

  int _visited = 0;
}
