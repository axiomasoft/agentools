# Voice journey conformance

The conductor command is `python3 tools/platform_gate.py e2e --scenario voice-journey`.
It runs 19 exact PostgreSQL cases from `case-map.json`, including all 13 declared
ECO/V2 obligations, qualification checker fixtures and static analysis. Missing
cases, required skips, absent dependencies, isolation proof or source hashes fail.
The combined tier is `local-host-e2e`: native compiler/intake/runtime/Actions/
delivery are real; audio, model and external host/media boundaries are scripted.

For focused development use `python3 -B conformance/platform/voice/pg.py` with an
optional PHPUnit `--filter`. The shared bootstrap validates a restricted,
marked isolated database before mutation or provisions its own disposable Docker
database. `extensions/loqum/tests/Journey/VoiceJourneyTest.php` performs each
stimulus and assertion. PostgreSQL JSONB key ordering is immaterial; snapshot
comparison uses the existing canonical encoder and preserves all fields/types.

Cases cover partial/noise rejection, stable final replay with a real submitted
Action, stale epoch/context, separate display/playout/history, interruption and
explicit cancellation independence, expired UI refusal, consent/revoke/retention
handoff, lost TTS acknowledgement, text continuity, attempted provider switch
after disclosure, context changes, tenant reset and final payload conflict. The
runtime worker does not regenerate a response to recover media delivery.

Per-case observations include actual model transport attempts, native invocation
and usage counts, Action/message/command counts and the runnable example's actual
tuple. Qualification binds deployment/model revision, profile/bundle, task,
prompt, skills, tool schema, synthetic corpus and a hash of all registered suite
inputs. Any tuple/source change invalidates applicability. Required failures,
skips, missing observations or duplicate/unaccounted model IO reject the technical
certificate. This voice-local checker is not a general evaluation platform.

An accepted certificate is scoped to `technical-contract-only`. Acoustic quality,
Russian child speech, domain gold, real host/retention integration, live providers
and pilot remain `insufficient_evidence` with named prerequisites. Qualification
does not modify active-session pins or authorize production rollout. The scorer
fixture tests use synthetic metadata and do not constitute additional runtime,
live or product-pilot evidence.
