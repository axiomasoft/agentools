# P8.5 resource envelopes

**Date:** 2026-10-06
**Receipt:** `artifacts/P8-resource-baseline.json` (`p8-resource-baseline/v1`)
**Fingerprint:** `aea42242a9a08b0b39a853443a3a943f4a9822212ac8f385090e0d89145bd3e0`
**Host:** Linux 7.0.0-38-generic x86_64 / Intel(R) Core(TM) i7-14700K

Portable gates own depth/count/byte/page/lease caps. Timing is compared only on a matching fingerprint; a mismatch refuses a timing verdict. PHP and Dart speed are not compared.

## Limits

| ID | Status | PHP | Dart | Gate |
|:--|:--|:--|:--|:--|
| RL-001 | implemented | 32 | 32 | green |
| RL-002 | implemented | 512 | 512 | green |
| RL-003 | implemented | 1000 | 1000 | green |
| RL-004 | implemented | 10000 | 10000 | green |
| RL-005 | implemented | 64 | 64 | green |
| RL-006 | implemented | 64 | 64 | green |
| RL-007 | implemented | 8192 | 8192 | green |
| RL-008 | implemented | 8192 | 8192 | green |
| RL-012 | implemented | 500 | 500 | green |
| RL-013 | implemented | 1000 | 1000 | green |
| RL-014 | implemented | 64 | 64 | green |
| RL-015 | implemented | 30 | 30 | green |
| RL-016 | implemented | 300 | 300 | green |
| RL-017 | implemented | 60 | 60 | green |
| RL-018 | implemented | 259200 | 259200 | green |
| RL-019 | implemented | 300 | 300 | green |
| RL-009 | baseline_pending | 512 | None | informational |
| RL-010 | baseline_pending | None | None | informational |
| RL-011 | baseline_pending | None | None | informational |
| RL-020 | baseline_pending | None | None | informational |
| RL-021 | baseline_pending | None | None | informational |
| RL-022 | baseline_pending | None | None | informational |
| RL-023 | baseline_pending | None | None | informational |
| RL-024 | baseline_pending | None | None | informational |
| RL-025 | baseline_pending | None | None | informational |

Timing workloads use warmup 3 and 30 one-call samples. `BW-IDC-001` / `BW-SNAP-001` stay `baseline_pending` against P8.4 schedule evidence.
