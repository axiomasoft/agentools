/// The data carried exclusively in `_meta["dev.agentools/action"]`.
final class ActionProfile {
  ActionProfile({
    required this.sideEffect,
    this.confirmation,
    this.idempotentCommit,
    this.permissionsHint,
    this.contentOrigin,
    Map<String, dynamic>? extensionMembers,
  }) : extensionMembers = extensionMembers ?? const {} {
    if (!_isOpenValue(sideEffect)) {
      throw ArgumentError('side_effect must be a core or product extension value.');
    }
    if (confirmation != null && !_isOpenValue(confirmation!)) {
      throw ArgumentError('confirmation must be a core or product extension value.');
    }
    if (contentOrigin != null && !_isContentOrigin(contentOrigin!)) {
      throw ArgumentError('content_origin must be a core or product extension value.');
    }
    if (_baseRequiresConfirmation(sideEffect) && confirmation == 'none') {
      throw ArgumentError(
        'confirmation cannot weaken the required confirmation gate.',
      );
    }
  }

  final String sideEffect;
  final String? confirmation;
  final bool? idempotentCommit;
  final List<String>? permissionsHint;
  final String? contentOrigin;
  final Map<String, dynamic> extensionMembers;

  bool requiresConfirmation() {
    if (_baseRequiresConfirmation(sideEffect)) {
      return true;
    }
    return confirmation == 'confirm' || confirmation == 'double_confirm';
  }

  Map<String, dynamic> toJson() {
    final payload = <String, dynamic>{
      ...extensionMembers,
      'side_effect': sideEffect,
      if (confirmation != null) 'confirmation': confirmation,
      if (idempotentCommit != null) 'idempotent_commit': idempotentCommit,
      if (permissionsHint != null) 'permissions_hint': permissionsHint,
      if (contentOrigin != null) 'content_origin': contentOrigin,
    };
    return payload;
  }

  static bool _baseRequiresConfirmation(String sideEffect) {
    return sideEffect != 'read' && sideEffect != 'draft';
  }

  static bool _isOpenValue(String value) {
    const core = {
      'read',
      'draft',
      'write',
      'irreversible',
      'none',
      'confirm',
      'double_confirm',
    };
    return core.contains(value) ||
        RegExp(r'^[a-z][a-z0-9]*\.[a-z0-9_]+$').hasMatch(value);
  }

  static bool _isContentOrigin(String value) {
    return value == 'untrusted_external' ||
        RegExp(r'^[a-z][a-z0-9]*\.[a-z0-9_]+$').hasMatch(value);
  }
}
