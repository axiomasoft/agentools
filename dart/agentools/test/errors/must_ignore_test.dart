import 'dart:convert';

import 'package:agentools/agentools.dart';
import 'package:test/test.dart';

void main() {
  // IDE-39, IDE-40, IDE-41; unknown members survive a byte-for-byte replay.
  test('envelope preserves unknown members byte for byte', () {
    const json =
        '{\n  "kind": "vendor.future_event",\n  "payload": {"future_member": {"deep": true}}\n}';

    expect(MustIgnoreEnvelope.fromJson(json).toJson(), json);
  });

  // IDE-39, IDE-40; unknown action-profile keys do not block descriptor parsing.
  test('descriptor accepts unknown action profile members', () {
    final document = _descriptorDocument();
    _setActionProfileValue(document, 'x_batch_priority_hint', 'high');

    final descriptor = ToolDescriptor.fromMap(document);

    expect(
      descriptor.actionProfile.extensionMembers['x_batch_priority_hint'],
      'high',
    );
    expect(descriptor.actionProfile.requiresConfirmation(), isTrue);
  });

  // IDE-39, IDE-40, IDE-41; nested unknown profile members round-trip through JSON.
  test('descriptor round trips nested unknown profile members', () {
    final document = _descriptorDocument();
    _setActionProfileValue(
      document,
      'future_extension_field_unknown_to_this_revision',
      {
        'anything': 'здесь',
        'может': 'быть',
      },
    );
    final json = jsonEncode(document);

    expect(ToolDescriptor.fromJson(json).toJsonText(), json);
    expect(
      ToolDescriptor.fromJson(json)
          .actionProfile
          .extensionMembers
          .containsKey('future_extension_field_unknown_to_this_revision'),
      isTrue,
    );
  });

  // IDE-39, IDE-43; unknown confirmation values do not weaken the side_effect gate.
  test('unknown confirmation does not weaken read tools', () {
    final document = _descriptorDocument();
    document['annotations'] = {
      'readOnlyHint': true,
      'destructiveHint': false,
      'idempotentHint': true,
      'openWorldHint': false,
    };
    _setActionProfileValue(document, 'side_effect', 'read');
    _setActionProfileValue(document, 'confirmation', 'acme.custom_double_check');

    expect(
      ToolDescriptor.fromMap(document).actionProfile.requiresConfirmation(),
      isFalse,
    );
  });
}

Map<String, dynamic> _descriptorDocument() {
  return <String, dynamic>{
    'name': 'crm_note_append',
    'title': 'Добавить заметку к клиенту',
    'description':
        'Purpose: Дописывает заметку в карточку клиента.\nCall when: Нужно зафиксировать комментарий.\nRequired arguments: client_id, text\nExample: crm_note_append({"client_id":"C-42","text":"перезвонить"})',
    'inputSchema': {
      'type': 'object',
      'properties': {
        'client_id': {'type': 'string'},
        'text': {'type': 'string'},
      },
    },
    'outputSchema': {
      'type': 'object',
      'properties': {
        'note_id': {'type': 'string'},
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
        'permissions_hint': ['crm.note.write'],
      },
    },
  };
}

void _setActionProfileValue(
  Map<String, dynamic> document,
  String key,
  Object? value,
) {
  final meta = Map<String, dynamic>.from(document['_meta'] as Map);
  final action = Map<String, dynamic>.from(
    meta[ExtensionIdentity.dataKey] as Map,
  );
  action[key] = value;
  meta[ExtensionIdentity.dataKey] = action;
  document['_meta'] = meta;
}
