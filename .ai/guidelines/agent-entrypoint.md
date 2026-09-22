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
