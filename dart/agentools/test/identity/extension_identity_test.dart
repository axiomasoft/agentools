import 'package:agentools/agentools.dart';
import 'package:test/test.dart';

void main() {
  /// EXT-1, EXT-4, EXT-11; vector extidentity-001
  test('exposes the frozen identity constants', () {
    expect(ExtensionIdentity.category, 'Unofficial per SEP-2133');
    expect(ExtensionIdentity.identifier, 'dev.agentools/core');
    expect(ExtensionIdentity.breakingIdentifier, 'dev.agentools/core-v2');
    expect(ExtensionIdentity.dataKey, 'dev.agentools/action');
    expect(ExtensionIdentity.specRevision, '2026-08');
    expect(
      ExtensionIdentity.schemaIdBase,
      'https://agentools.dev/schema/2026-08/',
    );
  });

  /// EXT-12: offline schema resolution policy is explicit in code comments only.
  test('does not treat schema ids as fetchable URLs by convention', () {
    expect(
      ExtensionIdentity.schemaIdBase.startsWith('https://'),
      isTrue,
      reason:
          'Schema ids remain namespace identifiers, not download endpoints.',
    );
  });
}
