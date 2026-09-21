import '../tool/tool_descriptor.dart';

abstract interface class ToolDescriptorRegistry {
  ToolDescriptor? descriptor(String toolName);
}
