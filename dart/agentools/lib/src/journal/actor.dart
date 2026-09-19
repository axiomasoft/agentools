/// Actor that wrote one journal record.
final class Actor {
  Actor({required this.type, required this.id}) {
    if (type.isEmpty || id.isEmpty) {
      throw ArgumentError('An actor type and id are required.');
    }
  }

  final String type;
  final String id;

  Map<String, String> toMap() => {'type': type, 'id': id};
}
