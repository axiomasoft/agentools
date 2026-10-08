import 'package:agentools/agentools.dart';

void main() {
  var assertions = false;
  assert(() {
    assertions = true;
    return true;
  }());
  if (assertions) throw StateError('Probe must run without assertions');
  final actor = Actor(type: 'user', id: 'u');
  var rejected = 0;
  for (final build in <void Function()>[
    () => ActionEvent(
      eventId: '',
      actionId: 'action',
      seq: 0,
      expectedPrevSeq: -1,
      timestamp: '2026-10-08T10:00:00Z',
      actor: actor,
      kind: 'action_created',
      eventVersion: 0,
      specRevision: '2026-09',
      payload: {},
    ),
    () => UncommittedEvent(
      eventId: '',
      kind: 'action_created',
      eventVersion: 0,
      specRevision: '2026-09',
      actor: actor,
      timestamp: '2026-10-08T10:00:00Z',
      payload: {},
    ),
    () => EventStreamPage(events: [], lastSeq: -1, hasMore: false),
    () => SessionStateProjection(
      actionId: '',
      status: ActionStatus.created,
      seq: 0,
      specRevision: '2026-09',
    ),
  ]) {
    try {
      build();
    } on ArgumentError {
      rejected++;
    }
  }
  if (rejected != 4) throw StateError('Invalid objects escaped: $rejected');
  print('$rejected runtime rejections');
}
