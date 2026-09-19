import '../contracts/session_store.dart';
import '../journal/session_state_projection.dart';

/// Process-local projection cache. Production stores must keep projections
/// consistent with the event log.
final class InMemorySessionStore implements SessionStore {
  final Map<String, SessionStateProjection> _projections = {};

  @override
  Future<SessionStateProjection?> load(String actionId) async {
    return _projections[actionId];
  }

  @override
  Future<void> save(SessionStateProjection projection) async {
    _projections[projection.actionId] = projection;
  }
}
