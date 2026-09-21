import 'package:agentools/agentools.dart';
import 'package:test/test.dart';

void main() {
  /// EXT-10; structural must-ignore for extra top-level keys (DR-01 §J9)
  test('accepts a document with an extra top-level key', () {
    final document = <String, Object?>{
      ExtensionIdentity.dataKey: {'side_effect': 'write'},
      'unexpected_top_level': {'ignored': true},
    };

    expect(ExtensionMeta.acceptsTopLevelDocument(document), isTrue);
    expect(ExtensionMeta.actionData(document), {'side_effect': 'write'});
  });

  /// EXT-16: unknown own-prefix keys are ignored, not rejected
  test('ignores unknown keys under the own vendor prefix', () {
    final meta = <String, Object?>{
      ExtensionIdentity.dataKey: {'side_effect': 'write'},
      'dev.agentools/future-feature': {'enabled': true},
    };

    expect(ExtensionMeta.ignorableOwnVendorKeys(meta), [
      'dev.agentools/future-feature',
    ]);
    expect(ExtensionMeta.actionData(meta), {'side_effect': 'write'});
  });
}
