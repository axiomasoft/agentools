import 'package:agentools/agentools.dart';
import 'package:test/test.dart';

void main() {
  group('ExtensionKey.parseOwn', () {
    /// EXT-7, EXT-8; vector extidentity-001
    test('parses the single declared data key', () {
      final result = ExtensionKey.parseOwn(ExtensionIdentity.dataKey);
      expect(result, isA<ExtensionKeyParsed>());
      expect((result as ExtensionKeyParsed).key.value, ExtensionIdentity.dataKey);
    });

    /// EXT-6; vector extidentity-002
    group('rejects a version in the data key', () {
      for (final key in [
        'dev.agentools/action-v1',
        'dev.agentools/action/v1',
      ]) {
        test('rejects $key', () {
          final result = ExtensionKey.parseOwn(key);
          expect(result, isA<VersionedDataKeyReject>());
          expect(
            (result as VersionedDataKeyReject).message,
            '$key puts a version in the extension data key.',
          );
        });
      }
    });

    /// EXT-8
    test('rejects malformed own vendor key', () {
      final result = ExtensionKey.parseOwn('dev.agentools/action/');
      expect(result, isA<InvalidVendorKeyReject>());
      expect(
        (result as InvalidVendorKeyReject).message,
        'dev.agentools/action/ does not match the AgenTools vendor-key grammar.',
      );
    });

    /// EXT-10
    test('rejects a claimed foreign vendor key', () {
      final result = ExtensionKey.parseOwn('acme.crm/action');
      expect(result, isA<ForeignVendorKeyReject>());
      expect(
        (result as ForeignVendorKeyReject).message,
        'acme.crm/action does not use the dev.agentools vendor prefix.',
      );
    });

    /// EXT-7
    test('rejects another own-prefix key that is not the declared data key', () {
      final result = ExtensionKey.parseOwn('dev.agentools/profile');
      expect(result, isA<InvalidVendorKeyReject>());
      expect(
        (result as InvalidVendorKeyReject).message,
        'dev.agentools/profile is not the declared AgenTools data key.',
      );
    });
  });
}
