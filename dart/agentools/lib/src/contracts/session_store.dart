import '../journal/session_state_projection.dart';

/// Materialized action-state projection. The event log remains the source of truth.
abstract interface class SessionStore {
  Future<SessionStateProjection?> load(String actionId);

  Future<void> save(SessionStateProjection projection);
}
