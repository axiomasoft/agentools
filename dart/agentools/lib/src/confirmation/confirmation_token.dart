import 'dart:convert';

import '../contracts/token_signer.dart';
import '../idempotency/arguments_fingerprint.dart';
import '../tool/descriptor_digest.dart';
import 'proposal.dart';

/// Opaque signed handle for the ordinary confirmation_token tool argument.
final class ConfirmationToken {
  ConfirmationToken._({
    required this.value,
    required this.proposalId,
    required this.proposalVersion,
    required this.actionId,
    required this.principal,
    required this.toolName,
    required this.argsFingerprint,
    required this.descriptorDigest,
    required this.expiresAt,
  });

  final String value;
  final String proposalId;
  final int proposalVersion;
  final String actionId;
  final String principal;
  final String toolName;
  final ArgumentsFingerprint argsFingerprint;
  final DescriptorDigest descriptorDigest;
  final DateTime expiresAt;

  /// Payload keys covered by the signed token body.
  static const coveredPayloadKeys = [
    'action_id',
    'args_fingerprint',
    'descriptor_digest',
    'expires_at',
    'principal',
    'proposal_id',
    'proposal_version',
    'tool_name',
  ];

  static ConfirmationToken issue(Proposal proposal, TokenSigner signer) {
    final payload = jsonEncode({
      'action_id': proposal.actionId,
      'args_fingerprint': proposal.argsFingerprint.value,
      'descriptor_digest': proposal.descriptorDigest.value,
      'expires_at': _formatAtom(proposal.expiresAt),
      'principal': proposal.principal,
      'proposal_id': proposal.proposalId,
      'proposal_version': proposal.proposalVersion,
      'tool_name': proposal.toolName,
    });
    final encoded = _encode(payload);
    final signature = _encode(signer.sign(encoded));
    return ConfirmationToken._(
      value: '$encoded.$signature',
      proposalId: proposal.proposalId,
      proposalVersion: proposal.proposalVersion,
      actionId: proposal.actionId,
      principal: proposal.principal,
      toolName: proposal.toolName,
      argsFingerprint: proposal.argsFingerprint,
      descriptorDigest: proposal.descriptorDigest,
      expiresAt: proposal.expiresAt,
    );
  }

  static ConfirmationToken parse(String token, TokenSigner signer) {
    final separator = token.indexOf('.');
    if (separator <= 0 || separator >= token.length - 1) {
      throw ArgumentError('The confirmation token signature is invalid.');
    }
    final encoded = token.substring(0, separator);
    final signatureSegment = token.substring(separator + 1);
    if (!signer.verify(encoded, _decode(signatureSegment))) {
      throw ArgumentError('The confirmation token signature is invalid.');
    }
    try {
      final payload = jsonDecode(_decode(encoded)) as Map<String, dynamic>;
      final expiresAt = DateTime.parse(_string(payload, 'expires_at'));
      return ConfirmationToken._(
        value: token,
        proposalId: _string(payload, 'proposal_id'),
        proposalVersion: _integer(payload, 'proposal_version'),
        actionId: _string(payload, 'action_id'),
        principal: _string(payload, 'principal'),
        toolName: _string(payload, 'tool_name'),
        argsFingerprint: ArgumentsFingerprint(_string(payload, 'args_fingerprint')),
        descriptorDigest: DescriptorDigest.fromValue(
          _string(payload, 'descriptor_digest'),
        ),
        expiresAt: expiresAt,
      );
    } catch (_) {
      throw ArgumentError('The confirmation token payload is invalid.');
    }
  }

  static String _formatAtom(DateTime value) {
    final utc = value.toUtc();
    final year = utc.year.toString().padLeft(4, '0');
    final month = utc.month.toString().padLeft(2, '0');
    final day = utc.day.toString().padLeft(2, '0');
    final hour = utc.hour.toString().padLeft(2, '0');
    final minute = utc.minute.toString().padLeft(2, '0');
    final second = utc.second.toString().padLeft(2, '0');
    return '$year-$month-${day}T$hour:$minute:$second+00:00';
  }

  static String _string(Map<String, dynamic> payload, String key) {
    final value = payload[key];
    if (value is! String) {
      throw ArgumentError('Missing token field $key.');
    }
    return value;
  }

  static int _integer(Map<String, dynamic> payload, String key) {
    final value = payload[key];
    if (value is! int) {
      throw ArgumentError('Missing token field $key.');
    }
    return value;
  }

  static String _encode(String value) {
    return base64Url.encode(utf8.encode(value)).replaceAll('=', '');
  }

  static String _decode(String value) {
    final padding = '=' * ((4 - value.length % 4) % 4);
    final decoded = base64Url.decode('$value$padding');
    return utf8.decode(decoded);
  }
}
