import 'package:agentools/agentools.dart';
import 'package:test/test.dart';

void main() {
  const negotiator = ExtensionNegotiator();

  /// EXT-13, EXT-14; vector extnegotiation-001
  test('negotiates only the declared current revision', () {
    final result = negotiator.negotiate(
      ExtensionAnnouncement.local(),
      extensionRequired: true,
    );

    expect(result.mode, NegotiationMode.agentools);
    expect(result.error, isNull);
  });

  /// EXT-14, EXT-15
  test('falls back to the core protocol for an unknown revision', () {
    final result = negotiator.negotiate(
      {ExtensionIdentity.identifier: <String, Object?>{}},
      extensionRequired: false,
    );

    expect(result.mode, NegotiationMode.coreProtocol);
    expect(result.error, isNull);
  });

  /// EXT-15
  test('returns the specific error when the extension is required', () {
    final result = negotiator.negotiate({}, extensionRequired: true);

    expect(result.mode, NegotiationMode.rejected);
    expect(result.error?.code, ToolErrorCodes.extensionUnsupported);
  });

  /// EXT-7, EXT-10; vector extension-001
  test('reads only the declared action data key', () {
    final meta = <String, Object?>{
      ExtensionIdentity.dataKey: {'side_effect': 'write'},
      'acme.crm/legacy-profile': {'side_effect': 'read'},
    };

    expect(
      ExtensionMeta.actionData(meta),
      {'side_effect': 'write'},
    );
  });

  /// EXT-16
  test('unknown extension entries do not cause a rejection', () {
    final result = negotiator.negotiate(
      {'acme.crm/feature': <String, Object?>{}},
      extensionRequired: false,
    );

    expect(result.mode, NegotiationMode.coreProtocol);
  });

  /// EXT-14; vector extnegotiation-002 (unknown revision behaves as absent)
  test('treats a mismatched specRevision as an unknown revision', () {
    final result = negotiator.negotiate(
      {
        ExtensionIdentity.identifier: {
          'specRevision': '2026-07',
        },
      },
      extensionRequired: false,
    );

    expect(result.mode, NegotiationMode.coreProtocol);
  });
}
