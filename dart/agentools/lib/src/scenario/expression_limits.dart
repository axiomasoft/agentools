/// SCN-16 through SCN-19: published, configurable, and never silently relaxed.
final class ExpressionLimits {
  ExpressionLimits({
    this.maxDepth = 32,
    this.maxLength = 512,
    this.maxCollectionSize = 1000,
    this.maxVisitedNodes = 10000,
  }) {
    for (final limit in [maxDepth, maxLength, maxCollectionSize, maxVisitedNodes]) {
      if (limit < 1) {
        throw ArgumentError('Expression limits must be positive.');
      }
    }
  }

  final int maxDepth;
  final int maxLength;
  final int maxCollectionSize;
  final int maxVisitedNodes;
}
