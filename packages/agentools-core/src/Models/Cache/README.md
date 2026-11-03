# Cache contract

`Kind` separates compiled artifacts, context fragments, retrieval results, implicit
prefix optimization, explicit provider resources and provider conversations.
Conversation state is never portable history and `Manager::resolve` refuses it.
The existing dated SDK cache/continuation APIs and Anthropic transport remain intact.

`Key::fromArray` accepts a closed versioned namespace. Its canonical tenant hash
includes kind, provider/deployment/model revision/API, credential binding/project/
region, tenant/security scope and version, profile/skills/tools/material/release
hashes, renderer/serialization versions. Personal keys require subject, retention
scope and deadline. Run/session IDs and arbitrary fields are rejected. Expiry/use
timestamps and current rights evidence belong to `Descriptor`, not the stable prefix.
Shared fixture `fixtures/platform/v1/cache/key.json` is synthetic, not provider evidence.

A descriptor stores opaque refs and technical metadata, never prompt/history bytes.
A hit rechecks the required current host `Guard`, provider storage rights, current
capability evidence, ordinary admission and the local revocation fence before return.
Personal expiry is also checked locally. The caller retains normal Runtime/transport
guards at invocation and disclosure; a resolution is not an authorization token.

A miss/stale/invalidated/unsupported/unknown resource is rebuilt through `Rebuilder`
from currently authorized sources and the existing compiler finalization. `Prepared`
enforces full input window/output reserve/safety budgets. Rebuild may update source
versions but cannot switch route, tenant, subject or security scope for cost. Both
hit and rebuilt plans use ordinary current owner admission. No cache service calls
model generation or Actions dispatch; Runtime remains the single invocation loop.

Provider lifecycle support defaults to unknown and requires bounded per-operation
`CapabilityEvidence`. Implicit prefixes do not expose create/refresh/delete as objects.
No existing provider adapter is declared cache-capable by this contract. Hosts must
map real APIs and credentials, including TTL, usage, lookup and deletion semantics.

`Economics` uses exact microcurrency inputs and checks one currency/pricing revision.
Missing prices/warm/storage costs, mismatched revisions or arithmetic overflow yield
unknown ROI. The formula is n*B*(input-cached) > warm+storage; equality is break-even.
Forecasts never guarantee savings. Automatic warming needs beneficial economics;
an explicit host performance policy may opt into unknown/nonpositive savings without
claiming a gain. The calculator fixture contains no live provider prices.
