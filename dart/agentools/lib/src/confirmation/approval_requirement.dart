final class ApprovalRequirement {
  ApprovalRequirement({
    required this.mode,
    required this.quorum,
    required this.separateFromProposer,
  }) {
    if (!const {'none', 'quorum'}.contains(mode) || quorum < 1) {
      throw ArgumentError(
        'Approval requirement needs a known mode and quorum of at least one.',
      );
    }
    if (mode == 'none' && (quorum != 1 || separateFromProposer)) {
      throw ArgumentError(
        'No-approval mode cannot select quorum or separation.',
      );
    }
  }

  factory ApprovalRequirement.none() =>
      ApprovalRequirement(mode: 'none', quorum: 1, separateFromProposer: false);

  final String mode;
  final int quorum;
  final bool separateFromProposer;

  bool matches(ApprovalRequirement other) =>
      mode == other.mode &&
      quorum == other.quorum &&
      separateFromProposer == other.separateFromProposer;
}
