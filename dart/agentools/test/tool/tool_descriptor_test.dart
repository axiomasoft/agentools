import 'dart:io';

import 'package:agentools/agentools.dart';
import 'package:test/test.dart';

void main() {
  final canonicalizer = Canonicalizer();

  Map<String, dynamic> document() => {
    'name': 'fina_payment_send',
    'title': 'Отправить платёж',
    'description':
        'Purpose: Отправляет платёж во внешнюю систему.\nCall when: Подтверждённый платёж готов к отправке.\nRequired arguments: amount_minor\nExample: fina_payment_send({"amount_minor":12500})',
    'inputSchema': {
      'type': 'object',
      'properties': {
        'amount_minor': {'type': 'integer'},
      },
    },
    'outputSchema': {
      'type': 'object',
      'properties': {
        'payment_id': {'type': 'string'},
      },
    },
    'annotations': {
      'readOnlyHint': false,
      'destructiveHint': true,
      'idempotentHint': true,
      'openWorldHint': true,
    },
    '_meta': {
      'dev.agentools/action': {
        'side_effect': 'irreversible',
        'confirmation': 'double_confirm',
        'idempotent_commit': true,
        'permissions_hint': ['fina.payment.send'],
        'content_origin': 'untrusted_external',
      },
    },
  };

  ToolDescriptor makeDescriptor([Map<String, dynamic>? overrides]) {
    final payload = Map<String, dynamic>.from(document());
    if (overrides != null) {
      payload.addAll(overrides);
    }
    return ToolDescriptor.fromMap(payload);
  }

  String phpGoldenDigest() {
    final phpTest = File('../../php/tests/Tool/ToolDescriptorTest.php');
    final match = RegExp(
      r'sha-256:[0-9a-f]{64}',
    ).firstMatch(phpTest.readAsStringSync());
    if (match == null) {
      throw StateError('Golden digest not found in ${phpTest.path}');
    }
    return match.group(0)!;
  }

  // TLP-18, TLP-19, TLP-20, TLP-21; межъязыковой якорь, фаза P8 обязана совпасть побайтно.
  test('calculates the golden descriptor digest from PHP anchor', () {
    final expected = phpGoldenDigest();
    final actual = makeDescriptor().digest(canonicalizer).value;
    // ignore: avoid_print
    print('php golden: $expected');
    // ignore: avoid_print
    print('dart actual: $actual');
    expect(actual, expected);
  });

  // TLP-19, TLP-22; a protected description change alters the digest.
  test('changing description changes the digest', () {
    final original = makeDescriptor().digest(canonicalizer);
    final changed = Map<String, dynamic>.from(document());
    changed['description'] = (changed['description'] as String).replaceAll(
      'платёж',
      'перевод',
    );

    final next = ToolDescriptor.fromMap(changed).digest(canonicalizer);
    // ignore: avoid_print
    print('original: ${original.value}');
    // ignore: avoid_print
    print('changed: ${next.value}');
    expect(next.value, isNot(original.value));
  });

  // TLP-19, TLP-22, TLP-23; presentation changes in the protected description are drift.
  test('rejects a descriptor changed after preparation', () {
    final expected = makeDescriptor().digest(canonicalizer);
    final changed = Map<String, dynamic>.from(document());
    changed['description'] = (changed['description'] as String).replaceAll(
      'платёж',
      'перевод',
    );

    expect(
      () =>
          ToolDescriptor.fromMap(changed).assertDigest(expected, canonicalizer),
      throwsA(
        isA<DescriptorDrift>().having(
          (error) => error.error.code,
          'code',
          ToolProfileErrorCodes.descriptorDrift,
        ),
      ),
    );
  });

  // TLP-10; catalog rejects a hint pair that contradicts its core side_effect.
  test('rejects inconsistent effect annotations', () {
    final payload = Map<String, dynamic>.from(document());
    final annotations = Map<String, dynamic>.from(
      payload['annotations'] as Map,
    );
    annotations['destructiveHint'] = false;
    payload['annotations'] = annotations;

    expect(
      () => ToolDescriptor.fromMap(payload),
      throwsA(isA<ArgumentError>()),
    );
  });

  // TLP-13; an unknown product side_effect must take the conservative gate path.
  test('unknown side_effect requires confirmation', () {
    final payload = Map<String, dynamic>.from(document());
    final meta = Map<String, dynamic>.from(payload['_meta'] as Map);
    final action = Map<String, dynamic>.from(
      meta['dev.agentools/action'] as Map,
    );
    action['side_effect'] = 'sec.incident_escalation';
    meta['dev.agentools/action'] = action;
    payload['_meta'] = meta;
    final annotations = Map<String, dynamic>.from(
      payload['annotations'] as Map,
    );
    annotations['destructiveHint'] = false;
    payload['annotations'] = annotations;

    expect(
      ToolDescriptor.fromMap(payload).actionProfile.requiresConfirmation(),
      isTrue,
    );
  });

  // TLP-24; unknown product content-origin values remain valid and preserved.
  test('preserves an open content_origin value', () {
    final payload = Map<String, dynamic>.from(document());
    final meta = Map<String, dynamic>.from(payload['_meta'] as Map);
    final action = Map<String, dynamic>.from(
      meta['dev.agentools/action'] as Map,
    );
    action['content_origin'] = 'crm.partner_feed';
    meta['dev.agentools/action'] = action;
    payload['_meta'] = meta;

    expect(
      ToolDescriptor.fromMap(payload).actionProfile.contentOrigin,
      'crm.partner_feed',
    );
  });
}
