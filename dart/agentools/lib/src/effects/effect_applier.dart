import '../journal/effect_record.dart';

/// Port for the product-owned external effect invocation.
abstract interface class EffectApplier {
  void apply(EffectRecord effect);
}
