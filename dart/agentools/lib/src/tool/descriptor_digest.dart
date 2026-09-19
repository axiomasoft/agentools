import 'dart:convert';

import 'package:crypto/crypto.dart';

import '../schema/canonicalizer.dart';
import 'tool_descriptor.dart';

/// SHA-256 digest of the five-member descriptor input defined by TLP-18.
final class DescriptorDigest {
  DescriptorDigest._(this.value);

  final String value;

  static DescriptorDigest forDescriptor(
    ToolDescriptor descriptor,
    Canonicalizer canonicalizer,
  ) {
    final canonical = canonicalizer.canonicalize(descriptor.digestInput());
    final digest = sha256.convert(utf8.encode(canonical)).toString();
    return DescriptorDigest._('sha-256:$digest');
  }

  static DescriptorDigest fromValue(String value) {
    if (!RegExp(r'^sha-256:[0-9a-f]{64}$').hasMatch(value)) {
      throw ArgumentError('Descriptor digest must be a sha-256 digest.');
    }
    return DescriptorDigest._(value);
  }
}
