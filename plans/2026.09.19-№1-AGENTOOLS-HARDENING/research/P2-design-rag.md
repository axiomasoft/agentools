# P2 design RAG — authorization and transaction-binding foundations

**Verified:** 2026-09-19  
**Boundary:** External sources constrain security properties and terminology. Agentools API gaps,
exact field names, error codes, class decomposition and phase ownership are repository design
decisions (`RAG:—`), not claims sourced from OWASP/NIST/IETF.

Perplexity was queried twice through the configured local `perplexity-web-mcp` stdio server: one
broad web+academic synthesis and one narrow primary-source check. It identified the official pages
and correctly separated direct requirements from design inference, including that the named OWASP
and NIST sources do not mandate a universal second-human/four-eyes algorithm. Every load-bearing
claim below was then rechecked by ordinary web search against the linked primary source. The first
native search attempt had a transient transport failure; two narrower retries succeeded.

| Evidence | Verified claim | P2 consequence |
|:--|:--|:--|
| RAG:✅ [OWASP Authorization — Deny by Default](https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html#deny-by-default), [Validate Permissions on Every Request](https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html#validate-the-permissions-on-every-request), accessed 2026-09-19 | Authorization defaults deny and is validated on every request rather than assumed from an earlier step or UI path. | Missing policy/context/decision is deny; commit re-evaluates current authorization through one application-wide trusted seam. |
| RAG:✅ [OWASP Transaction Authorization §1.1](https://cheatsheetseries.owasp.org/cheatsheets/Transaction_Authorization_Cheat_Sheet.html#11-transaction-authorization-method-has-to-allow-a-user-to-identify-and-acknowledge-significant-transaction-data), [§2.3](https://cheatsheetseries.owasp.org/cheatsheets/Transaction_Authorization_Cheat_Sheet.html#23-transaction-verification-data-should-be-generated-server-side), accessed 2026-09-19 | Users verify significant transaction data; significant verification data is generated/stored server-side and protected from client modification. | Actual arguments/descriptor/policy binding is authoritative and caller cannot select or downgrade the method. |
| RAG:✅ [OWASP Transaction Authorization §2.5](https://cheatsheetseries.owasp.org/cheatsheets/Transaction_Authorization_Cheat_Sheet.html#25-application-should-control-which-transaction-state-transitions-are-allowed), [§2.6](https://cheatsheetseries.owasp.org/cheatsheets/Transaction_Authorization_Cheat_Sheet.html#26-application-should-prevent-attacker-from-modifying-the-transaction-data), [§2.8](https://cheatsheetseries.owasp.org/cheatsheets/Transaction_Authorization_Cheat_Sheet.html#28-system-should-check-each-transaction-execution-and-make-sure-it-has-been-properly-authorized), accessed 2026-09-19 | State transitions are server-controlled; modified transaction data invalidates/restarts authorization; a final execution gate verifies proper authorization and mitigates TOCTOU/skipped checks. | Approval is bound to immutable record/version/digests and current authorization is checked at commit. |
| RAG:✅ [OWASP Transaction Authorization §2.9](https://cheatsheetseries.owasp.org/cheatsheets/Transaction_Authorization_Cheat_Sheet.html#29-authorization-credentials-should-only-be-valid-during-a-limited-time-period), [§2.10](https://cheatsheetseries.owasp.org/cheatsheets/Transaction_Authorization_Cheat_Sheet.html#210-authorization-credentials-should-be-unique-for-every-operation), accessed 2026-09-19 | Authorization credentials have a limited window and are unique per operation to reduce replay. | P2 binds expiry and operation identity; P3 remains owner of durable one-shot enforcement. |
| RAG:✅ [NIST SP 800-162](https://csrc.nist.gov/pubs/sp/800/162/upd2/final), accessed 2026-09-19 | ABAC evaluates attributes of subject, object, requested operation and, where applicable, environment against policy/rules/relationships. | `AuthorizationRequest` is context-rich; `principal + toolName` alone is insufficient for the hardened path. |
| RAG:✅ [NIST SP 800-53 Rev. 5 AC-5](https://csrc.nist.gov/pubs/sp/800/53/r5/upd1/final), accessed 2026-09-19 | Separation of duties requires organization-defined duties and supporting access authorizations; the control does not prescribe one universal quorum algorithm. | Dual control/quorum is an explicit policy result with named organization semantics, never a global caller boolean or external-law claim. |
| RAG:✅ [RFC 9700 §2.3](https://www.rfc-editor.org/rfc/rfc9700.html#section-2.3), accessed 2026-09-19 | OAuth security BCP recommends least privilege and resource/action/audience restriction verified for every request. | This is corroborating guidance for exact resource/action binding; P2 does not require OAuth or JWT. |
| RAG:✅ [RFC 9396](https://www.rfc-editor.org/rfc/rfc9396.html), accessed 2026-09-19 | Rich authorization can express fine-grained locations/actions/data/identifiers/privileges and protects authorization details from tampering where integrity matters. | P2 models fine-grained authorization context, but its concrete fields and non-OAuth port are local design choices. |

## Facts intentionally not promoted

- OWASP transaction authorization guidance discusses a user's second factor, not a mandatory second human.
- NIST AC-5 makes separated duties organization-defined; it does not prove every action needs dual control.
- NIST ABAC and OAuth RAR do not mandate Agentools class names, JSON fields, policy repositories or token format.
- RFC 9700/9396 do not make Agentools an OAuth implementation and are not a reason to choose JWT.
- External guidance does not prove the current code safe; repository tests and call signatures establish current behavior.
