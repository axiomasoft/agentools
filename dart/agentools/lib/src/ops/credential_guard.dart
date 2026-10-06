import '../errors/tool_execution_error.dart';
import '../errors/tool_execution_result.dart';
import 'ops_error_codes.dart';

/// Rejects credentials that were not issued for this implementation
/// (SEC-1, SEC-2, SEC-5, SEC-6).
///
/// The guard is fail-closed: the resource audience and the trusted issuer set
/// are declared by the implementation (SEC-2 items 1 and 3) and have no
/// built-in defaults; an empty issuer set rejects every access token. A `null`
/// verdict only means "not rejected" and never grants rights (SEC-1). Every
/// rejection is a non-retryable `permission` tool-execution error with its own
/// code, so it stays distinguishable from CNF-18..CNF-21 (SEC-3).
///
/// The credential shape follows `DriverCredential` of the published vector
/// schema: `kind`, `source`, `issuer`, `audience` and `integrity`. Rejection
/// context carries `reason`, `security_rule` and the offending
/// `credential_field`.
final class CredentialGuard {
  CredentialGuard({
    required this.resourceAudience,
    required Iterable<String> trustedIssuers,
  }) : trustedIssuers = Set.unmodifiable(trustedIssuers) {
    if (resourceAudience.isEmpty) {
      throw ArgumentError('The resource audience must not be empty.');
    }
    if (this.trustedIssuers.any((issuer) => issuer.isEmpty)) {
      throw ArgumentError('A trusted issuer must not be empty.');
    }
  }

  static const sourceTrustedCredential = 'trusted_credential';
  static const _maxArgumentDepth = 64;

  /// Identifier of our own resource (SEC-2 item 1).
  final String resourceAudience;

  /// Declared trusted issuer set (SEC-2 item 3).
  final Set<String> trustedIssuers;

  /// SEC-1, SEC-2, SEC-5, SEC-6; validates a credential presented as an
  /// access token.
  ToolExecutionResult? validateAccessToken(Map<String, dynamic> credential) {
    final kind = credential['kind'];
    if (kind == 'confirmation_token') {
      return _rejectCredential('credential_role_confusion', 'SEC-6', 'kind');
    }
    if (kind != 'access_token') {
      return _rejectCredential('unsupported_credential_kind', 'SEC-1', 'kind');
    }
    if (credential['source'] != sourceTrustedCredential) {
      return _rejectCredential('data_sourced_access_token', 'SEC-5', 'source');
    }
    if (credential['audience'] != resourceAudience) {
      return _rejectCredential('foreign_access_token', 'SEC-1', 'audience');
    }
    final issuer = credential['issuer'];
    if (issuer is! String || !trustedIssuers.contains(issuer)) {
      return _rejectCredential('untrusted_issuer', 'SEC-2', 'issuer');
    }
    if (credential['integrity'] != 'verified') {
      return _rejectCredential('unverified_access_token', 'SEC-2', 'integrity');
    }
    return null;
  }

  /// SEC-5; an access token found anywhere inside tool arguments is data,
  /// never a credential.
  ///
  /// Nested maps and lists are inspected. Structures deeper than the
  /// inspection limit (including cyclic ones) are rejected as well, because
  /// the absence of a smuggled token cannot be proven for them.
  ToolExecutionResult? rejectTokensInArguments(Map<String, dynamic> arguments) {
    return _scan(arguments, '', 1);
  }

  /// SEC-6; confirmation tokens cannot stand in for access tokens.
  ToolExecutionResult? rejectConfirmationTokenAsAccess(String tokenKind) {
    if (tokenKind != 'confirmation_token') {
      return null;
    }
    return _failure({
      'reason': 'credential_role_confusion',
      'security_rule': 'SEC-6',
    });
  }

  ToolExecutionResult? _scan(Object? value, String pointer, int depth) {
    final Iterable<MapEntry<String, Object?>> children;
    if (value is Map) {
      if (value['kind'] == 'access_token') {
        return _smuggled('data_sourced_access_token', pointer);
      }
      children = value.entries.map(
        (entry) => MapEntry(entry.key.toString(), entry.value),
      );
    } else if (value is List) {
      children = value.asMap().entries.map(
        (entry) => MapEntry(entry.key.toString(), entry.value),
      );
    } else {
      return null;
    }
    for (final child in children) {
      final nested = child.value;
      if (nested is! Map && nested is! List) {
        continue;
      }
      final childPointer = '$pointer/${_escapePointerToken(child.key)}';
      if (depth + 1 > _maxArgumentDepth) {
        return _smuggled('argument_depth_exceeded', childPointer);
      }
      final found = _scan(nested, childPointer, depth + 1);
      if (found != null) {
        return found;
      }
    }
    return null;
  }

  ToolExecutionResult _smuggled(String reason, String pointer) {
    return _failure({
      'reason': reason,
      'security_rule': 'SEC-5',
      'argument_pointer': pointer,
    });
  }

  ToolExecutionResult _rejectCredential(
    String reason,
    String rule,
    String field,
  ) {
    return _failure({
      'reason': reason,
      'security_rule': rule,
      'credential_field': field,
    });
  }

  ToolExecutionResult _failure(Map<String, String> context) {
    return ToolExecutionResult.failure(
      ToolExecutionError(
        code: OpsErrorCodes.foreignAccessToken,
        category: 'permission',
        retryable: false,
        title: 'Access token was not issued for this implementation.',
        context: Map<String, dynamic>.unmodifiable(context),
      ),
    );
  }

  static String _escapePointerToken(String token) {
    return token.replaceAll('~', '~0').replaceAll('/', '~1');
  }
}
