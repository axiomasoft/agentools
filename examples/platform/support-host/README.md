# Synthetic support host

This private composition uses the existing headless support runtime with a second
host boundary and a support-owned context projection. The same core compiler,
Action registry, runtime, PostgreSQL intake and delivery implementations are
used. The separate direct Action example composes the existing durable confirmed
write and effect services; it needs no Assistant, transcript or model. Both
owners, the UI event, and the model are scripted. No Chatom, Loqum or education
owner is required.

The two demonstrations have separate Action definitions and host authorizations:
the runtime exposes the read-only ticket registry, while the independent direct
path uses the document write definition and synthetic UI confirmation. The
`Tools` catalog demonstrates an additional code-owned intersection and is not
an effect executor or the runtime registry. This composition does not implement
an assistant-proposed support write, nor does it qualify a live provider or a
production host. The example vault and destination are local test owners.

Run one isolated PostgreSQL case with `python3 conformance/platform/second-host/pg.py --filter testRuntimeAndDelivery`.
The test lifecycle verifies the restricted database owner and isolation marker
before schema creation, or creates a labelled disposable PostgreSQL container.
`php conformance/platform/second-host/boundary.php` checks the host boundary.
The registered `second-host` suite runs all exact cases and identifies its
host-contract evidence tier.
