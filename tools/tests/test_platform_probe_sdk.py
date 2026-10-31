"""Offline P1.2 experiments; never import a live provider or the future P4 adapter."""
import copy
import json
from pathlib import Path
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[2]
PROBE = ROOT / "tools/platform_probe_sdk.php"
DOCUMENT = ROOT / "docs/platform/sdk-capabilities.json"


def fixture():
    return {
        "op": "exchange", "now": 100,
        "snapshot": {"expires_at": 200, "capabilities": {
            name: {"support": "supported", "evidence": "probe_verified"}
            for name in ("text", "tools", "structured", "stream")
        }},
        "request": {
            "tenant_id": "tenant-a", "invocation_id": "inv-1", "snapshot_ref": "snapshot-offline",
            "credential_ref": {"tenant_id": "tenant-a", "account_id": "account-a", "provider": "anthropic", "region": "fixture-region", "ref": "secret-ref-only"},
            "deployment": {"tenant_id": "tenant-a", "account_id": "account-a", "provider": "anthropic", "region": "fixture-region"},
            "model": "fixture-model", "max_tokens": 64, "system": "Trusted host policy",
            "messages": [{"role": "user", "content": "Untrusted user text"}],
            "tools": [{"name": "read_record", "description": "Read", "input_schema": {"type": "object", "properties": {}}},
                      {"name": "write_record", "description": "Write proposal", "input_schema": {"type": "object", "properties": {}}}],
            "capabilities": ["text", "tools"], "deadline": 150,
            "admin_state": "enabled", "authorized": True, "emergency_guard_allows": True,
        },
        "http": {"status": 200, "request_id": "req-offline", "body": {
            "id": "msg-offline", "type": "message", "role": "assistant", "model": "actual-fixture-revision",
            "content": [{"type": "tool_use", "id": "toolu_stable", "name": "write_record", "input": {"value": "new"}}],
            "stop_reason": "tool_use", "usage": {"input_tokens": 7, "output_tokens": 2},
        }},
    }


def frames():
    return [
        {"type": "message_start", "message": {"type": "message", "id": "msg-offline", "role": "assistant", "model": "actual-fixture-revision", "content": [], "stop_reason": None, "usage": {"input_tokens": 7, "output_tokens": 0}}},
        {"type": "content_block_start", "index": 0, "content_block": {"type": "text", "text": ""}},
        {"type": "content_block_delta", "index": 0, "delta": {"type": "text_delta", "text": "hello"}},
        {"type": "content_block_stop", "index": 0},
        {"type": "message_delta", "delta": {"stop_reason": "end_turn"}, "usage": {"output_tokens": 2}},
        {"type": "message_stop"},
    ]


def sse(events):
    return "".join(f"event: {event['type']}\ndata: {json.dumps(event)}\n\n" for event in events)


class ProbeCase(unittest.TestCase):
    def call(self, data, expected=None, io=None):
        process = subprocess.run(["php", str(PROBE)], input=json.dumps(data), text=True, capture_output=True, cwd=ROOT, timeout=10)
        self.assertEqual(process.stderr, "", process.stderr)
        output = json.loads(process.stdout)
        self.assertEqual(output["effects"], 0)
        if expected:
            self.assertEqual(process.returncode, 1, output)
            self.assertEqual(output["error"], expected)
        else:
            self.assertEqual(process.returncode, 0, output)
            self.assertTrue(output["ok"])
        if io is not None:
            self.assertEqual(output["io_calls"], io)
        return output.get("result", output)


class SelectionTests(ProbeCase):
    def document(self):
        return json.loads(DOCUMENT.read_text())

    def test_direct_choice_has_no_sdk_dependency(self):
        result = self.call({"op": "select"}, io=0)
        self.assertEqual((result["choice"], result["loop_owner"], result["loop_proven"]), ("C", "AgenTools", False))

    def test_required_unsupported_is_rejected(self):
        doc = self.document()
        next(c for c in doc["capabilities"] if c["required_for_loop"])["support"] = "unsupported"
        self.call({"selection": doc}, "UNSUPPORTED_REQUIRED_CAPABILITY", 0)

    def test_unknown_required_is_not_zero_or_success(self):
        doc = self.document()
        next(c for c in doc["capabilities"] if c["required_for_loop"])["support"] = "unknown"
        self.call({"selection": doc}, "UNKNOWN_REQUIRED_CAPABILITY", 0)

    def test_fabricated_direct_sdk_pin_is_rejected(self):
        doc = self.document()
        doc["selected_transport"]["version"] = "0.6.8"
        self.call({"selection": doc}, "FABRICATED_SDK_PIN", 0)

    def test_exact_version_does_not_prove_sdk_loop(self):
        doc = self.document()
        doc["selected_transport"].update(driver="laravel-ai", package="laravel/ai", version="1.3.0")
        doc["loop_proven"] = True
        self.call({"selection": doc}, "UNPROVEN_SDK_LOOP", 0)

    def test_unproven_direct_loop_cannot_be_self_certified(self):
        doc = self.document()
        doc["loop_proven"] = True
        self.call({"selection": doc}, "UNPROVEN_LOOP", 0)

    def test_unknown_driver_and_empty_required_set_fail(self):
        doc = self.document()
        doc["selected_transport"]["driver"] = "guessed-sdk"
        self.call({"selection": doc}, "UNKNOWN_DRIVER", 0)
        doc = self.document()
        doc["capabilities"] = []
        self.call({"selection": doc}, "MISSING_REQUIRED_CAPABILITIES", 0)


class DirectContractTests(ProbeCase):
    def test_write_is_only_a_proposal_with_stable_id_and_separate_policy(self):
        result = self.call(fixture(), io=1)
        self.assertEqual(result["response"]["proposals"][0]["provider_ref"], "toolu_stable")
        self.assertEqual(result["request"]["body"]["system"], "Trusted host policy")
        self.assertEqual(result["request"]["headers"]["anthropic-version"], "2026-10-21")
        self.assertEqual(result["provider_request_id"], "req-offline")
        self.assertNotEqual(result["response"]["requested_model"], result["response"]["actual_model"])

    def test_read_result_replay_keeps_provider_reference(self):
        data = fixture()
        data["request"]["messages"] += [
            {"role": "assistant", "content": [{"type": "tool_use", "id": "toolu_read", "name": "read_record", "input": {}}]},
            {"role": "user", "content": [{"type": "tool_result", "tool_use_id": "toolu_read", "content": "record"}]},
        ]
        data["http"]["body"].update(content=[{"type": "text", "text": "record summarized"}], stop_reason="end_turn")
        result = self.call(data, io=1)
        self.assertEqual(result["request"]["body"]["messages"][-1]["content"][0]["tool_use_id"], "toolu_read")
        self.assertEqual(result["response"]["text"], "record summarized")

    def test_missing_usage_and_zero_are_distinct(self):
        data = fixture()
        del data["http"]["body"]["usage"]
        self.assertIsNone(self.call(data)["response"]["usage"]["output_tokens"])
        data["http"]["body"]["usage"] = {"input_tokens": 0, "output_tokens": 0}
        self.assertEqual(self.call(data)["response"]["usage"]["output_tokens"], 0)

    def test_invalid_usage_fails(self):
        data = fixture()
        data["http"]["body"]["usage"]["input_tokens"] = -1
        self.call(data, "INVALID_USAGE", 1)

    def test_structured_result_and_refusal(self):
        data = fixture()
        data["request"]["capabilities"].append("structured")
        data["request"]["structured_schema"] = {"type": "object", "properties": {"answer": {"type": "string"}}, "required": ["answer"], "additionalProperties": False}
        data["http"]["body"].update(content=[{"type": "text", "text": '{"answer":"ok"}'}], stop_reason="end_turn")
        self.assertEqual(self.call(data)["response"]["structured"], {"answer": "ok"})
        data["http"]["body"]["content"][0]["text"] = '{"answer":7}'
        self.call(data, "INVALID_STRUCTURED_RESULT", 1)
        data["http"]["body"].update(content=[{"type": "text", "text": "refused"}], stop_reason="refusal")
        self.assertTrue(self.call(data)["response"]["refusal"])

    def test_proposal_identity_is_tenant_scoped_and_hash_conflicts_fail(self):
        first = self.call(fixture())["response"]["proposals"][0]
        self.assertEqual(first, self.call(fixture())["response"]["proposals"][0])
        data = fixture()
        for part in (data["request"], data["request"]["credential_ref"], data["request"]["deployment"]):
            part["tenant_id"] = "tenant-b"
        self.assertNotEqual(first["identity"], self.call(data)["response"]["proposals"][0]["identity"])
        data = fixture()
        duplicate = copy.deepcopy(data["http"]["body"]["content"][0])
        data["http"]["body"]["content"].append(duplicate)
        self.assertEqual(len(self.call(data)["response"]["proposals"]), 1)
        duplicate["input"]["value"] = "different"
        self.call(data, "PROPOSAL_HASH_CONFLICT", 1)

    def test_missing_id_and_unknown_finish_fail(self):
        data = fixture()
        del data["http"]["body"]["content"][0]["id"]
        self.call(data, "INVALID_PROPOSAL", 1)
        data = fixture()
        data["http"]["body"]["stop_reason"] = "future_reason"
        self.call(data, "UNKNOWN_FINISH_REASON", 1)

    def test_transport_fault_is_unknown_and_never_retried(self):
        data = fixture()
        data["http"] = {"fault": "timeout_after_send"}
        self.call(data, "NETWORK_OUTCOME_UNKNOWN", 1)
        data["http"] = {"status": 429}
        self.call(data, "RATE_LIMITED", 1)


class BeforeIOTests(ProbeCase):
    def test_unsupported_unknown_unverified_and_expired_fail_before_io(self):
        for support, expected in [("unknown", "UNKNOWN_CAPABILITY"), ("unsupported", "UNSUPPORTED_CAPABILITY")]:
            with self.subTest(support=support):
                data = fixture()
                data["snapshot"]["capabilities"]["tools"]["support"] = support
                self.call(data, expected, 0)
        data = fixture()
        data["snapshot"]["capabilities"]["tools"]["evidence"] = "documented"
        self.call(data, "UNVERIFIED_CAPABILITY", 0)
        data = fixture()
        data["snapshot"]["expires_at"] = 100
        self.call(data, "EXPIRED_EVIDENCE", 0)

    def test_pinned_snapshot_does_not_bypass_current_guards(self):
        for key, value, expected in [("admin_state", "incident_blocked", "ADMIN_STATE_BLOCKED"), ("authorized", False, "CURRENT_GUARD_DENIED"), ("emergency_guard_allows", False, "CURRENT_GUARD_DENIED"), ("cancelled", True, "CANCELLED"), ("deadline", 100, "DEADLINE_EXCEEDED")]:
            with self.subTest(key=key):
                data = fixture()
                data["request"][key] = value
                self.call(data, expected, 0)

    def test_credentials_cannot_cross_binding(self):
        for key in ("tenant_id", "account_id", "provider", "region"):
            with self.subTest(key=key):
                data = fixture()
                data["request"]["credential_ref"][key] = "other"
                self.call(data, "CREDENTIAL_BINDING_MISMATCH", 0)

    def test_callbacks_and_remote_effect_tools_cannot_execute(self):
        data = fixture()
        data["automatic_effects"] = True
        self.call(data, "AUTOMATIC_EFFECTS_FORBIDDEN", 0)
        for key in ("callback", "handle", "type"):
            with self.subTest(key=key):
                data = fixture()
                data["request"]["tools"][0][key] = "server-executor"
                self.call(data, "AUTOMATIC_EFFECTS_FORBIDDEN", 0)

    def test_optional_audio_and_opaque_options_are_typed_failures(self):
        data = fixture()
        data["request"]["capabilities"].append("audio")
        data["snapshot"]["capabilities"]["audio"] = {"support": "supported", "evidence": "probe_verified"}
        self.call(data, "UNSUPPORTED_CAPABILITY", 0)
        data = fixture()
        data["request"]["provider_options"] = {"url": "https://other.invalid"}
        self.call(data, "UNSUPPORTED_PROVIDER_OPTION", 0)


class StreamFaultTests(ProbeCase):
    def streaming(self, events=None):
        data = fixture()
        data["op"] = "stream"
        data["request"]["capabilities"].append("stream")
        data["http"]["body"] = sse(frames() if events is None else events)
        return data

    def test_terminal_and_cumulative_usage(self):
        events = frames()
        events.insert(-1, {"type": "message_delta", "delta": {"stop_reason": "end_turn"}, "usage": {"output_tokens": 3}})
        result = self.call(self.streaming(events), io=1)
        self.assertEqual(result["response"]["text"], "hello")
        self.assertEqual(result["response"]["usage"]["output_tokens"], 3)
        self.assertEqual([e["sequence"] for e in result["events"]], list(range(len(events))))

    def test_missing_duplicate_and_late_terminal_are_rejected(self):
        self.call(self.streaming(frames()[:-1]), "MISSING_TERMINAL", 1)
        self.call(self.streaming(frames() + [{"type": "message_stop"}]), "DUPLICATE_TERMINAL", 1)
        self.call(self.streaming(frames() + [{"type": "message_delta", "delta": {}}]), "EVENT_AFTER_TERMINAL", 1)

    def test_error_and_unknown_events_cannot_be_success(self):
        self.call(self.streaming(frames()[:3] + [{"type": "error", "error": {"type": "overloaded_error"}}]), "STREAM_PROVIDER_ERROR", 1)
        self.call(self.streaming(frames()[:1] + [{"type": "future_event"}]), "UNKNOWN_STREAM_EVENT", 1)

    def test_partial_tool_proposal_never_runs_handler(self):
        events = frames()
        events[1]["content_block"] = {"type": "tool_use", "id": "toolu_stream", "name": "write_record", "input": {}}
        events[2]["delta"] = {"type": "input_json_delta", "partial_json": '{"value":'}
        events.insert(3, {"type": "content_block_delta", "index": 0, "delta": {"type": "input_json_delta", "partial_json": '"new"}'}})
        events[-2]["delta"]["stop_reason"] = "tool_use"
        result = self.call(self.streaming(events), io=1)
        self.assertEqual(result["response"]["proposals"][0]["provider_ref"], "toolu_stream")
        self.assertEqual(result["response"]["proposals"][0]["arguments"], {"value": "new"})
        events[3]["delta"]["partial_json"] = "broken"
        self.call(self.streaming(events), "MALFORMED_JSON", 1)


class EvidenceTests(unittest.TestCase):
    def test_required_matrix_is_not_optional_and_runtime_is_not_claimed(self):
        document = json.loads(DOCUMENT.read_text())
        required = {c["name"] for c in document["capabilities"] if c["required_for_loop"]}
        self.assertTrue({"proposal_only", "stable_tool_ids", "stream_terminal_errors", "nullable_usage", "credential_isolation", "structured_output", "client_tool_result_replay"}.issubset(required))
        self.assertFalse(document["loop_proven"])
        self.assertIsNone(document["selected_transport"]["version"])
        self.assertEqual(document["supported_loop_mode"], "separate-transport")


if __name__ == "__main__":
    unittest.main()
