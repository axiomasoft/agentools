import 'action_event.dart';

abstract interface class EventUpcaster {
  bool supports(ActionEvent event);

  ActionEvent upcast(ActionEvent event);
}
