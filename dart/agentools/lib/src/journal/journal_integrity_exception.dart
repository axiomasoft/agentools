import 'lifecycle_exception.dart';

/// ALC-37: a cryptographic journal-chain failure is distinct from a version conflict.
final class JournalIntegrityException extends LifecycleException {
  JournalIntegrityException(super.message);
}
