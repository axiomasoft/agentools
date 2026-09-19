/// Declared long-operation execution paths (LOP-1, LOP-2).
enum LongOperationPath {
  taskBacked('task'),
  synchronous('sync');

  const LongOperationPath(this.value);

  final String value;
}
