import 'value_lifecycle.dart';

/// Known const branches plus a product fallback pattern — not a closed Dart enum.
sealed class OpenEnum {
  OpenEnum._(this.value);

  factory OpenEnum(
    String value,
    List<String> known, {
    Map<String, ValueLifecycle> lifecycle = const {},
  }) {
    if (!known.contains(value) && !_fallbackPattern.hasMatch(value)) {
      throw ArgumentError(
        "Value '$value' matches neither a known const nor the product fallback pattern.",
      );
    }

    final stage = lifecycle[value];
    if (stage == ValueLifecycle.removed) {
      throw ArgumentError(
        "Value '$value' was removed after its deprecation window.",
      );
    }

    if (known.contains(value)) {
      return KnownOpenEnum(value, lifecycle[value] ?? ValueLifecycle.active);
    }
    return UnknownOpenEnum(value);
  }

  static OpenEnum producerValue(
    String value,
    List<String> known, {
    Map<String, ValueLifecycle> lifecycle = const {},
  }) {
    final stage = lifecycle[value] ?? ValueLifecycle.active;
    if (stage == ValueLifecycle.deprecated) {
      throw ArgumentError(
        "Value '$value' is deprecated and must not be emitted in new records.",
      );
    }

    return OpenEnum(value, known, lifecycle: lifecycle);
  }

  static final RegExp _fallbackPattern = RegExp(r'^[a-z][a-z0-9]*\.[a-z0-9_]+$');

  final String value;

  bool get isKnown;

  ValueLifecycle get lifecycle;
}

final class KnownOpenEnum extends OpenEnum {
  KnownOpenEnum(super.value, this._lifecycle) : super._();

  final ValueLifecycle _lifecycle;

  @override
  bool get isKnown => true;

  @override
  ValueLifecycle get lifecycle => _lifecycle;
}

final class UnknownOpenEnum extends OpenEnum {
  UnknownOpenEnum(super.value) : super._();

  @override
  bool get isKnown => false;

  @override
  ValueLifecycle get lifecycle => ValueLifecycle.active;
}
