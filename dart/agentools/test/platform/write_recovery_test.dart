import 'package:test/test.dart';
import 'write_recovery_worker.dart';

void main() {
  for (final item in recoveryCases()) {
    test('recovery.${item['id']}', () {
      expect(exerciseRecovery(item), item['expected']);
    });
  }
}
