import '../errors/tool_execution_error.dart';
import '../errors/tool_execution_result.dart';
import 'ops_error_codes.dart';

/// Rejects tokens not issued for this implementation (SEC-1..SEC-6).
final class CredentialGuard {
  static const _ourAudience = 'agentools';

  ToolExecutionResult? validateAccessToken(
    Map<String, dynamic> credential, {
    List<String> trustedIssuers = const ['issuer-trusted'],
  }) {
    if (credential['kind'] != 'access_token') {
      return null;
    }

    final reason = _rejectionReason(credential, trustedIssuers);
    if (reason == null) {
      return null;
    }

    return ToolExecutionResult.failure(
      ToolExecutionError(
        code: OpsErrorCodes.foreignAccessToken,
        category: 'permission',
        retryable: false,
        title: 'Access token was not issued for this implementation.',
        context: {
          'reason': reason,
          'security_rule': 'SEC-3',
          'stimulus_pointer': '/given/driver_state/credentials/0/audience',
        },
      ),
    );
  }

  /// SEC-5; tokens embedded in tool arguments are never accepted.
  ToolExecutionResult? rejectTokensInArguments(
    Map<String, dynamic> arguments,
  ) {
    for (final value in arguments.values) {
      if (value is Map && value['kind'] == 'access_token') {
        return _foreignTokenFailure('token_in_argument');
      }
    }
    return null;
  }

  /// SEC-6; confirmation tokens cannot stand in for access tokens.
  ToolExecutionResult? rejectConfirmationTokenAsAccess(String tokenKind) {
    if (tokenKind != 'confirmation_token') {
      return null;
    }
    return _foreignTokenFailure('confirmation_token_as_access');
  }

  String? _rejectionReason(
    Map<String, dynamic> credential,
    List<String> trustedIssuers,
  ) {
    if (credential['audience'] != _ourAudience) {
      return 'foreign_access_token';
    }
    if (credential['source'] == 'passthrough') {
      return 'passthrough_token';
    }
    final issuer = credential['issuer'];
    if (issuer is! String || !trustedIssuers.contains(issuer)) {
      return 'untrusted_issuer';
    }
    if (credential['integrity'] != 'verified') {
      return 'integrity_unverified';
    }
    return null;
  }

  ToolExecutionResult _foreignTokenFailure(String reason) {
    return ToolExecutionResult.failure(
      ToolExecutionError(
        code: OpsErrorCodes.foreignAccessToken,
        category: 'permission',
        retryable: false,
        title: 'Access token was not issued for this implementation.',
        context: {'reason': reason, 'security_rule': 'SEC-3'},
      ),
    );
  }
}
