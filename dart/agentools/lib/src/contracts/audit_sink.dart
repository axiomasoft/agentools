/// Optional export port; absence of an implementation must not break the package (AOB-25).
abstract interface class AuditSink {
  void export(Map<String, dynamic> auditEvent);
}
