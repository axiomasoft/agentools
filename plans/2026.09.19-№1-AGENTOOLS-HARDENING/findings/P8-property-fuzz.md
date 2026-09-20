# P8.2 property/fuzz corpus

**Date:** 2026-09-20  
**Generator:** `adversarial-corpus/1.0.0` (`sha256-counter`)  
**Receipt:** `artifacts/P8-property-fuzz.json`

Language-neutral cases are generated once from seed `1` and consumed by PHP and
Dart evaluators. P6 JCS oracle bytes remain authoritative; generated typed
inputs compare canonical bytes and rejection class, not exception text.

## Evidence

| Check | Result |
|:--|:--|
| Replay of `(generator_version, seed, case_index)` | byte-identical |
| Bounded corpus + oracle vectors | green differential |
| PHP-only ASCII-sort inject | red |
| Dart-only duplicate-key skip inject | red |
| Shrink + promoted regression | `fixtures/hardening/adversarial/regressions/jcs-utf16-sort.min.json` |

## Families

JCS oracle/typed/raw-invalid, token one-bit MAC mutation, expression depth
`limit-1/limit/limit+1`, baggage item bounds, illegal host-code expression.

Extended 10k ES6-number and full 213-vector corpora remain declared (`CRP-JCS-10K`,
`CRP-SEM-213`) and are not silently marked proved.
