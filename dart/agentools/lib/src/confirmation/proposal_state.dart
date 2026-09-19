/// Lifecycle state of a prepared confirmation proposal.
enum ProposalState {
  pending('pending'),
  approved('approved'),
  rejected('rejected'),
  revoked('revoked'),
  expired('expired'),
  superseded('superseded');

  const ProposalState(this.value);

  final String value;
}
