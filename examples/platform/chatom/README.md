# Headless support/editor hosts and scripted destination

Explicit test composition only, no Edufy model imports. HostHistory demonstrates bounded
owner reads for independent support and editor purposes. Production uses existing
AgenToolsPlatform\Chatom\NativeHistory, with actual Chatom viewer readers and the host's
current binding. Never replace unavailable owners with HostHistory automatically.

ScriptedDestination is the sole owner of fixture_membership/fixture_messages in a proven
isolated test schema. It exercises native-key dedup/status, current owner permission locks,
lost ACK and actual process death. The production extension touches none of those tables;
it only calls public Destination APIs and existing platform repositories.

The native TextComposition/TextResponsePublisher/PostgresDeliveryOwner path remains intact.
The extension consumes their saved DeliverySubmission key/export, not a rewritten key.
The fixtures' minimal display JSON and IdentityKeys keys are not actual Chatom API evidence.
See the repository's extensions/chatom/pin-matrix.json.

Conductor registrations: chatom / chatom-delivery (test and pg-test --isolated).
All local evidence is host_contract_harness. Real driver/remote host qualification is pending.
