<!-- generated from .ai/guidelines/agent-entrypoint.md by scripts/build-agent-docs.sh -->

# AgenTools

- Edit this context only in `.ai/guidelines/agent-entrypoint.md`, then run
  `bash scripts/build-agent-docs.sh`.
- This is a protocol-first repository. Keep `spec/`, shared `fixtures/`,
  `conformance/`, and the PHP and Dart SDKs semantically aligned.
- The current revision and repository map are in `README.md`; API surfaces are
  documented in `php/API.md` and `dart/*/API.md`.
- Development hierarchy, impact routing, commands, generated-file ownership,
  and definition of done are in `DEVELOPMENT.md`.
- The repository is private. Do not publish packages or claim public readiness
  without the recorded owner release gate.
- For plan work, use the matching `plans/*/status.md` and `plan.md`; open only
  the current phase and its declared contracts.
- Preserve unrelated worktree changes. Run the language-specific tests plus the
  affected conformance and validation gates.

<!-- swissknifeman:hub:start -->
<!-- swissknifeman hub: stub — skills and task-commands are connected natively; index and priority rules are not duplicated here. -->
<!-- swissknifeman:registry sha=695f52f0312f skills=209 -->
<!-- Full index: `skiller status` or generate-hub.sh --target <dir> --full-index. -->
<!-- swissknifeman:hub:end -->
<!-- maind:healthcheck hash=75400a328a0b (managed by maind — do not edit manually) -->
## mAInd — temporary project context and graph

Memory defaults to this project's private scope `project:agentools`. Read `ns:*` or `global`
only for an explicit cross-project request and when the scope is authorized.

Store only short useful temporary context: 48-hour default TTL, 72-hour maximum,
800 characters, at most 8 live entries per scope. Use one replaceable `active-handoff`
for continuity, then forget it when work finishes. Keep plans, decisions, progress,
commit history and durable rules in repository artifacts. Empty memory is normal.

Use `maind health --cwd` for connection symptoms or setup changes; repair with
`maind health --cwd --fix`. A memory outage does not block ordinary repository work.
Project connections: `maind graph --project agentools`.
<!-- /maind:healthcheck -->
