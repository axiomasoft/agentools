import 'dart:convert';
import 'dart:io';

import 'package:agentools/agentools.dart';
import 'package:test/test.dart';

/// GREASE vectors from fixtures/2026-08/vectors/l1/grease-*.json — unit replay.
void main() {
  // grease-001, IDE-38, IDE-39, IDE-43, OVR-19, TLP-13; unknown side_effect keeps gate.
  test('grease-001 unknown side effect requires confirmation', () {
    final profile = _profileFromVector('grease-001');

    expect(profile.requiresConfirmation(), isTrue);
    expect(profile.sideEffect, 'acme.custom_period_close');
  });

  // grease-002, IDE-39, IDE-40; unknown action-profile keys are accepted and preserved.
  test('grease-002 unknown action profile key is preserved', () {
    final profile = _profileFromVector('grease-002');

    expect(profile.extensionMembers['x_batch_priority_hint'], 'high');
    expect(profile.requiresConfirmation(), isTrue);
  });

  // grease-003, CFH-48, CFH-49; unknown top-level descriptor keys are accepted.
  test('grease-003 unknown top level descriptor key is preserved', () {
    final document = _descriptorDocument();
    document['x_catalog_tag'] = 'beta-2026';

    final descriptor = ToolDescriptor.fromMap(document);

    expect(descriptor.extraMembers['x_catalog_tag'], 'beta-2026');
    expect(descriptor.actionProfile.requiresConfirmation(), isTrue);
  });

  // grease-004, IDE-39, OVR-20, TLP-14; unknown confirmation does not weaken gate.
  test('grease-004 unknown confirmation keeps write gate', () {
    final profile = _profileFromVector('grease-004');

    expect(profile.requiresConfirmation(), isTrue);
    expect(profile.confirmation, 'acme.custom_double_check');
  });
}

ActionProfile _profileFromVector(String vectorId) {
  final vector = _vector(vectorId);
  final given = vector['given'] as Map<String, dynamic>;
  final tools = given['tools'] as List<dynamic>;
  final tool = tools.first as Map<String, dynamic>;
  final meta = tool['_meta'] as Map<String, dynamic>;
  final profile = meta[ExtensionIdentity.dataKey] as Map<String, dynamic>;

  final extensionMembers = <String, dynamic>{};
  for (final entry in profile.entries) {
    if (!{
      'side_effect',
      'confirmation',
      'idempotent_commit',
      'permissions_hint',
      'content_origin',
    }.contains(entry.key)) {
      extensionMembers[entry.key] = entry.value;
    }
  }

  return ActionProfile(
    sideEffect: profile['side_effect'] as String,
    confirmation: profile['confirmation'] as String?,
    idempotentCommit: profile['idempotent_commit'] as bool?,
    permissionsHint: (profile['permissions_hint'] as List<dynamic>?)
        ?.cast<String>(),
    contentOrigin: profile['content_origin'] as String?,
    extensionMembers: extensionMembers,
  );
}

Map<String, dynamic> _vector(String vectorId) {
  final path = '../../fixtures/2026-08/vectors/l1/$vectorId.json';
  final decoded = jsonDecode(File(path).readAsStringSync());
  return Map<String, dynamic>.from(decoded as Map);
}

Map<String, dynamic> _descriptorDocument() {
  return <String, dynamic>{
    'name': 'crm_deal_stage_update',
    'title': 'Изменить стадию сделки',
    'description':
        'Purpose: Переводит сделку на другую стадию воронки.\nCall when: Стадия сделки должна измениться.\nRequired arguments: deal_id, stage\nExample: crm_deal_stage_update({"deal_id":"D-9","stage":"переговоры"})',
    'inputSchema': {
      'type': 'object',
      'properties': {
        'deal_id': {'type': 'string'},
        'stage': {'type': 'string'},
      },
    },
    'outputSchema': {
      'type': 'object',
      'properties': {
        'deal_id': {'type': 'string'},
        'stage': {'type': 'string'},
      },
    },
    'annotations': {
      'readOnlyHint': false,
      'destructiveHint': false,
      'idempotentHint': true,
      'openWorldHint': false,
    },
    '_meta': <String, dynamic>{
      ExtensionIdentity.dataKey: <String, dynamic>{
        'side_effect': 'write',
        'confirmation': 'confirm',
        'idempotent_commit': true,
        'permissions_hint': ['crm.deal.write'],
      },
    },
  };
}
