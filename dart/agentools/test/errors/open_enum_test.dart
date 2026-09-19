import 'package:agentools/agentools.dart';
import 'package:test/test.dart';

void main() {
  const sideEffectKnown = ['read', 'draft', 'write', 'irreversible'];

  // IDE-35, IDE-36; known core values are accepted without the product fallback.
  test('accepts known core values', () {
    final value = OpenEnum('write', sideEffectKnown);

    expect(value.isKnown, isTrue);
    expect(value.lifecycle, ValueLifecycle.active);
  });

  // IDE-35, IDE-37; product extension values use the fallback branch.
  test('accepts product extension values', () {
    final value = OpenEnum('acme.custom_period_close', sideEffectKnown);

    expect(value.isKnown, isFalse);
    expect(value, isA<UnknownOpenEnum>());
  });

  // IDE-36; values outside both branches are rejected.
  test('rejects values outside known and fallback branches', () {
    expect(
      () => OpenEnum('not-a-valid-open-enum', sideEffectKnown),
      throwsArgumentError,
    );
  });

  // IDE-45; deprecated values remain readable.
  test('accepts deprecated values for readers', () {
    final value = OpenEnum(
      'legacy',
      ['legacy', 'active'],
      lifecycle: {'legacy': ValueLifecycle.deprecated},
    );

    expect(value.lifecycle, ValueLifecycle.deprecated);
  });

  // IDE-47; removed values are rejected after the deprecation window.
  test('rejects removed values', () {
    expect(
      () => OpenEnum(
        'retired',
        ['retired'],
        lifecycle: {'retired': ValueLifecycle.removed},
      ),
      throwsArgumentError,
    );
  });

  // IDE-46; producers must not emit deprecated values in new records.
  test('rejects deprecated values for producers', () {
    expect(
      () => OpenEnum.producerValue(
        'legacy',
        ['legacy'],
        lifecycle: {'legacy': ValueLifecycle.deprecated},
      ),
      throwsArgumentError,
    );
  });

  // IDE-25, IDE-33; unknown product categories remain valid open-enum members.
  test('error category open enum accepts product categories', () {
    final category = ErrorCategoryOpenEnum.parse('fina.custom_category');

    expect(category.isKnown, isFalse);
    expect(category.value, 'fina.custom_category');
    expect(ErrorCategory.defaultRetryable(ErrorCategory.validation), isFalse);
  });
}
