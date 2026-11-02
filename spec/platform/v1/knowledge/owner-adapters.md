# External Library and Files contracts

Library/Content adapters implement `MaterialAccessPolicy` and supply exact Material
projections. The adapter must bind owner, access-policy and license references to
the trusted tenant/subject/purpose, check current audience and scopes, and include
emergency/revoke/publication restrictions. A cached license ref alone cannot
authorize extraction or disclosure. Unknown owner results fail closed. Owner
credentials, consent and rights mutation remain outside this extension.

Files/Vaulter adapters implement `DocumentSource`: load the supplied immutable
bytesRef/version, enforce owner scan/hold/retention rules, and return the exact
source bytes whose SHA-256 matches Material. Resolve derivatives/OCR page text
through exact versioned owner refs with a documented canonical mapping. Never
silently resolve latest, fetch another tenant's bytes, or replace a missing source
with generated text. The adapter must enforce its resource limits before loading
large files. PDF/OCR dependencies, credentials and unsafe file parsing are adapter
responsibilities; no such dependency is required by the knowledge domain or core.

Source corrections create a new owner version and release and pass the previous
projection to the atomic publication API. Owner revoke events call local
`invalidate` immediately. Downstream owners consume exact-version dependency
invalidation at their own boundaries. Historic results retain original hashes and
are annotated by tombstones, never silently rewritten.

Embedding adapters implement `EmbeddingTransport` and must return finite vectors
of the requested dimension in the exact requested embedding space. The retrieval
owner validates these results at its boundary. The ingest package makes no paid
calls and does not supply a production embedding provider.
