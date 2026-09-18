import 'package:agentools/agentools.dart';
import 'package:test/test.dart';

void main() {
  /// EXT-8; vector extidentity-001
  test('accepts a valid own vendor meta key', () {
    final result = VendorMetaKey.parseOwn('dev.agentools/action');
    expect(result, isA<VendorMetaKeyAccepted>());
  });

  /// EXT-6
  test('rejects a version suffix in an own vendor meta key', () {
    final result = VendorMetaKey.parseOwn('dev.agentools/action-v1');
    expect(result, isA<VendorMetaKeyRejected>());
  });

  /// EXT-9
  test('rejects a reserved MCP vendor prefix', () {
    final result = VendorMetaKey.parseOwn('com.modelcontextprotocol/feature');
    expect(result, isA<VendorMetaKeyRejected>());
  });
}
