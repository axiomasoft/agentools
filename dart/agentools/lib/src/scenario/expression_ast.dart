/// Nodes of the closed expression grammar; the evaluator walks these, not source text.
sealed class ExpressionNode {}

final class LiteralNode extends ExpressionNode {
  LiteralNode(this.value);

  final Object? value;
}

final class ReferenceNode extends ExpressionNode {
  ReferenceNode(this.value);

  final String value;
}

final class MissingNode extends ExpressionNode {
  MissingNode(this.reference);

  final String reference;
}

final class NotNode extends ExpressionNode {
  NotNode(this.operand);

  final ExpressionNode operand;
}

final class BinaryNode extends ExpressionNode {
  BinaryNode({
    required this.operator,
    required this.left,
    required this.right,
  });

  final String operator;
  final ExpressionNode left;
  final ExpressionNode right;
}
