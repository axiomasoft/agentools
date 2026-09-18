# Покрытие правил ревизии `2026-08`

> Производный артефакт: ручная правка запрещена. Генератор: `python3 tools/spec_lint.py spec/revisions/2026-08 --coverage --write`.

## Сводка

| Метрика | Значение |
|:--|--:|
| Правил всего | 400 |
| Тестируемых правил | 394 |
| Тестируемых правил с покрытием | 384 |
| MUST/MUST NOT с покрытием | 383 / 383 |

### По уровням

| Уровень | Тестируемых | С покрытием |
|:--|--:|--:|
| L1 | 275 | 270 |
| L2 | 77 | 76 |
| L3 | 42 | 38 |

### По классам корпуса

| Класс | Носителей | Ссылок на правила |
|:--|--:|--:|
| behavior | 213 | 424 |
| artifact | 89 | 142 |
| harness | 13 | 30 |
| golden | 10 | 53 |

## Правила

| Rule ID | Документ | Слово | Уровень | Классы | Векторы / причина Not testable |
|:--|:--|:--|:--|:--|:--|
| OVR-1 | 00_overview.md | MUST | L1 | artifact | artifact:ovrpin-001 |
| OVR-2 | 00_overview.md | MUST NOT | L1 | artifact | artifact:ovrlegacy-001, artifact:ovrlegacy-002 |
| OVR-3 | 00_overview.md | MUST NOT | L1 | artifact | artifact:ovrboundary-001 |
| OVR-4 | 00_overview.md | MUST NOT | L1 | artifact | artifact:ovrboundary-001 |
| OVR-5 | 00_overview.md | MUST NOT | L1 | artifact | artifact:ovrboundary-001 |
| OVR-6 | 00_overview.md | MUST | L1 | artifact | artifact:ovrword-001, artifact:ovrword-002 |
| OVR-7 | 00_overview.md | MUST | L1 | artifact | artifact:ovrline-001, artifact:ovrline-002, artifact:ovrnotest-001, artifact:ovrnotest-002 |
| OVR-8 | 00_overview.md | MUST | L1 | artifact | artifact:ovrfield-001 |
| OVR-9 | 00_overview.md | MUST | L1 | artifact | artifact:ovrboundary-001 |
| OVR-10 | 00_overview.md | MUST | L1 | artifact | artifact:ovrdup-001, artifact:ovrdup-002 |
| OVR-11 | 00_overview.md | MUST NOT | L1 | artifact | artifact:ovrdup-001, artifact:ovrdup-002 |
| OVR-12 | 00_overview.md | MUST | L1 | artifact | artifact:ovrline-001, artifact:ovrline-002 |
| OVR-13 | 00_overview.md | MUST | L1 | artifact | artifact:ovrlevel-001 |
| OVR-14 | 00_overview.md | MUST | L1 | artifact | artifact:ovrdev-001, artifact:ovrdev-002 |
| OVR-15 | 00_overview.md | MUST NOT | L1 | artifact | artifact:ovrdesc-001, artifact:ovrdesc-002 |
| OVR-16 | 00_overview.md | MUST | L1 | artifact | artifact:ovrenum-001, artifact:ovrenum-002 |
| OVR-17 | 00_overview.md | MUST | L1 | artifact | artifact:ovrref-001, artifact:ovrref-002 |
| OVR-18 | 00_overview.md | MUST | L1 | artifact | artifact:ovrexample-001, artifact:ovrexample-002 |
| OVR-19 | 00_overview.md | MUST | L1 | artifact, behavior | behavior:grease-001, artifact:ovrsfx-001, artifact:ovrsfx-002, artifact:ovrsfx-003 |
| OVR-20 | 00_overview.md | MUST | L1 | artifact, behavior | behavior:grease-004, artifact:ovrsfx-001, artifact:ovrsfx-002, artifact:ovrsfx-003 |
| OVR-21 | 00_overview.md | MUST NOT | L1 | artifact | artifact:ovrsfx-002 |
| OVR-22 | 00_overview.md | MUST NOT | L1 | artifact | artifact:ovrrev-001 |
| OVR-23 | 00_overview.md | MUST | L1 | artifact | artifact:ovrrev-001 |
| OVR-24 | 00_overview.md | MUST | L1 | artifact | artifact:ovrlevel-001 |
| OVR-25 | 00_overview.md | MUST | L1 | artifact | artifact:ovrgloss-001 |
| OVR-26 | 00_overview.md | MUST NOT | L1 | artifact | artifact:ovrlegacy-001, artifact:ovrlegacy-002 |
| OVR-27 | 00_overview.md | MUST | L1 | artifact | artifact:ovrgloss-001 |
| EXT-1 | 01_extension-identity.md | MUST | L1 | artifact | artifact:extidentity-001 |
| EXT-2 | 01_extension-identity.md | MUST NOT | L1 | artifact | artifact:extidentity-001 |
| EXT-3 | 01_extension-identity.md | MUST | L1 | artifact | artifact:extidentity-001 |
| EXT-4 | 01_extension-identity.md | MUST | L1 | artifact | artifact:extidentity-001 |
| EXT-5 | 01_extension-identity.md | MUST | L1 | artifact | artifact:extidentity-001 |
| EXT-6 | 01_extension-identity.md | MUST NOT | L1 | artifact | artifact:extidentity-001, artifact:extidentity-002 |
| EXT-7 | 01_extension-identity.md | MUST | L1 | behavior | behavior:extension-001 |
| EXT-8 | 01_extension-identity.md | MUST | L1 | artifact | artifact:extidentity-001 |
| EXT-9 | 01_extension-identity.md | MUST NOT | L1 | artifact | artifact:extidentity-001 |
| EXT-10 | 01_extension-identity.md | MUST NOT | L1 | behavior | behavior:extension-001 |
| EXT-11 | 01_extension-identity.md | MUST | L1 | artifact | artifact:extnegotiation-001 |
| EXT-12 | 01_extension-identity.md | MUST NOT | L1 | artifact | artifact:extnegotiation-001 |
| EXT-13 | 01_extension-identity.md | MUST | L1 | artifact | artifact:extnegotiation-001 |
| EXT-14 | 01_extension-identity.md | MUST | L1 | artifact | artifact:extnegotiation-001, artifact:extnegotiation-002 |
| EXT-15 | 01_extension-identity.md | MUST | L1 | artifact | artifact:extnegotiation-001 |
| EXT-16 | 01_extension-identity.md | MUST NOT | L1 | artifact | artifact:extnegotiation-001 |
| EXT-17 | 01_extension-identity.md | MUST NOT | L1 | artifact | artifact:extnegotiation-001 |
| TLP-1 | 02_tool-contract-profile.md | MUST | L1 | artifact | artifact:toolcontract-007 |
| TLP-2 | 02_tool-contract-profile.md | MUST | L1 | artifact | artifact:toolcontract-002, artifact:tooldescription-001, artifact:tooldescription-002 |
| TLP-3 | 02_tool-contract-profile.md | SHOULD | L1 | — | — |
| TLP-4 | 02_tool-contract-profile.md | SHOULD | L1 | — | — |
| TLP-5 | 02_tool-contract-profile.md | MUST | L1 | artifact | artifact:toolcontract-003, artifact:toolcontract-004 |
| TLP-6 | 02_tool-contract-profile.md | MUST NOT | L1 | artifact | artifact:toolcontract-007 |
| TLP-7 | 02_tool-contract-profile.md | MUST | L1 | artifact | artifact:toolcontract-001, artifact:toolcontract-101 |
| TLP-8 | 02_tool-contract-profile.md | SHOULD | L1 | — | — |
| TLP-9 | 02_tool-contract-profile.md | MUST | L1 | artifact | artifact:toolcontract-007 |
| TLP-10 | 02_tool-contract-profile.md | MUST | L1 | artifact | artifact:toolcontract-007 |
| TLP-11 | 02_tool-contract-profile.md | MUST NOT | L1 | behavior | behavior:confpolicy-001, behavior:confpolicy-101 |
| TLP-12 | 02_tool-contract-profile.md | MUST | L1 | artifact | artifact:toolcontract-005, artifact:toolcontract-105 |
| TLP-13 | 02_tool-contract-profile.md | MUST NOT | L1 | behavior | behavior:grease-001, behavior:profile-001 |
| TLP-14 | 02_tool-contract-profile.md | MAY | L1 | behavior | behavior:grease-004 |
| TLP-15 | 02_tool-contract-profile.md | SHOULD | L1 | — | — |
| TLP-16 | 02_tool-contract-profile.md | SHOULD | L1 | — | — |
| TLP-17 | 02_tool-contract-profile.md | MUST NOT | L1 | artifact | artifact:tooldigest-001, artifact:tooldigest-002 |
| TLP-18 | 02_tool-contract-profile.md | MUST | L1 | artifact | artifact:tooldigest-001 |
| TLP-19 | 02_tool-contract-profile.md | MUST | L1 | artifact | artifact:tooldigest-001 |
| TLP-20 | 02_tool-contract-profile.md | MUST NOT | L1 | artifact | artifact:tooldigest-001 |
| TLP-21 | 02_tool-contract-profile.md | MUST | L1 | artifact | artifact:tooldigest-001 |
| TLP-22 | 02_tool-contract-profile.md | MUST | L1 | artifact | artifact:tooldigest-001 |
| TLP-23 | 02_tool-contract-profile.md | MUST NOT | L1 | artifact | artifact:tooldigest-001 |
| TLP-24 | 02_tool-contract-profile.md | MAY | L3 | — | — |
| TLP-25 | 02_tool-contract-profile.md | MUST | L3 | behavior | behavior:profileuntrusted-001, behavior:profileuntrusted-101 |
| TLP-26 | 02_tool-contract-profile.md | MUST NOT | L1 | artifact | artifact:toolauthoring-001, artifact:toolauthoring-101 |
| TLP-27 | 02_tool-contract-profile.md | MUST | L1 | artifact | artifact:toolauthoring-002, artifact:toolauthoring-102 |
| TLP-28 | 02_tool-contract-profile.md | MUST | L1 | artifact | artifact:toolauthoring-003, artifact:toolauthoring-103 |
| TLP-29 | 02_tool-contract-profile.md | MUST NOT | L1 | artifact | artifact:dosnetwork-001 |
| TLP-30 | 02_tool-contract-profile.md | MUST | L1 | artifact | artifact:toolauthoring-004, artifact:toolauthoring-104 |
| TLP-31 | 02_tool-contract-profile.md | MUST | L1 | artifact | artifact:toolauthoring-005, artifact:toolauthoring-105 |
| TLP-32 | 02_tool-contract-profile.md | MUST | L1 | artifact | artifact:toolauthoring-006, artifact:toolauthoring-106 |
| TLP-33 | 02_tool-contract-profile.md | MUST | L1 | artifact | artifact:dosdepth-001 |
| TLP-34 | 02_tool-contract-profile.md | MUST | L1 | artifact | artifact:dossubschemas-001 |
| ALC-1 | 03_action-lifecycle.md | MUST | L1 | behavior | behavior:lifecycle-001 |
| ALC-2 | 03_action-lifecycle.md | MUST NOT | L1 | behavior | behavior:lifecyclefold-001, behavior:lifecyclefold-101 |
| ALC-3 | 03_action-lifecycle.md | MUST | L1 | behavior | behavior:lifecyclefold-002, behavior:lifecyclefold-102 |
| ALC-4 | 03_action-lifecycle.md | MUST NOT | L1 | behavior | behavior:lifecyclefold-003, behavior:lifecyclefold-103 |
| ALC-5 | 03_action-lifecycle.md | MUST | L1 | behavior | behavior:lifecycle-001 |
| ALC-6 | 03_action-lifecycle.md | MUST | L1 | behavior | behavior:lifecyclefold-004, behavior:lifecyclefold-104 |
| ALC-7 | 03_action-lifecycle.md | MUST NOT | L1 | behavior | behavior:lifecyclefold-005, behavior:lifecyclefold-105 |
| ALC-8 | 03_action-lifecycle.md | MUST | L1 | behavior | behavior:confirmation-001 |
| ALC-9 | 03_action-lifecycle.md | MUST | L1 | behavior | behavior:lifecycle-001 |
| ALC-10 | 03_action-lifecycle.md | MUST | L1 | behavior | behavior:lifecycle-001 |
| ALC-11 | 03_action-lifecycle.md | MUST | L1 | behavior | behavior:lifecycle-002, behavior:lifecycle-003 |
| ALC-12 | 03_action-lifecycle.md | MUST | L1 | behavior | behavior:lifecycle-003 |
| ALC-13 | 03_action-lifecycle.md | MUST NOT | L1 | behavior | behavior:lifecycle-002, behavior:lifecycle-003 |
| ALC-14 | 03_action-lifecycle.md | MUST | L1 | artifact | artifact:lifecycleindex-001, artifact:lifecycleindex-002 |
| ALC-15 | 03_action-lifecycle.md | MUST | L1 | behavior | behavior:lifecyclefold-006, behavior:lifecyclefold-106 |
| ALC-16 | 03_action-lifecycle.md | MUST | L1 | behavior | behavior:lifecyclefold-007, behavior:lifecyclefold-107 |
| ALC-17 | 03_action-lifecycle.md | MUST | L1 | behavior | behavior:confirmation-001 |
| ALC-18 | 03_action-lifecycle.md | MUST NOT | L1 | behavior | behavior:lifecyclefold-008, behavior:lifecyclefold-108 |
| ALC-19 | 03_action-lifecycle.md | MUST | L1 | behavior | behavior:lifecycleconcurrency-001, behavior:lifecycleconcurrency-101 |
| ALC-20 | 03_action-lifecycle.md | MUST | L1 | behavior | behavior:lifecycleconcurrency-002, behavior:lifecycleconcurrency-102 |
| ALC-21 | 03_action-lifecycle.md | MUST | L1 | behavior | behavior:lifecycleconcurrency-003, behavior:lifecycleconcurrency-103 |
| ALC-22 | 03_action-lifecycle.md | MUST | L1 | behavior | behavior:lifecycleconcurrency-004, behavior:lifecycleconcurrency-104 |
| ALC-23 | 03_action-lifecycle.md | MUST NOT | L1 | behavior | behavior:lifecycleconcurrency-005, behavior:lifecycleconcurrency-105 |
| ALC-24 | 03_action-lifecycle.md | MUST | L1 | behavior | behavior:confirmation-001 |
| ALC-25 | 03_action-lifecycle.md | MUST | L1 | behavior | behavior:lifecycleconcurrency-006, behavior:lifecycleconcurrency-106 |
| ALC-26 | 03_action-lifecycle.md | MUST | L1 | behavior | behavior:lifecycleconcurrency-007, behavior:lifecycleconcurrency-107 |
| ALC-27 | 03_action-lifecycle.md | MUST | L1 | behavior | behavior:lifecycleconcurrency-008, behavior:lifecycleconcurrency-108 |
| ALC-28 | 03_action-lifecycle.md | MUST NOT | L1 | behavior | behavior:lifecycleintegrity-001, behavior:lifecycleintegrity-101 |
| ALC-29 | 03_action-lifecycle.md | MUST | L1 | behavior | behavior:lifecycle-001 |
| ALC-30 | 03_action-lifecycle.md | MUST | L1 | behavior | behavior:lifecycleintegrity-002, behavior:lifecycleintegrity-102 |
| ALC-31 | 03_action-lifecycle.md | MUST NOT | L1 | behavior | behavior:lifecycleintegrity-003, behavior:lifecycleintegrity-103 |
| ALC-32 | 03_action-lifecycle.md | MAY | L3 | — | — |
| ALC-33 | 03_action-lifecycle.md | MUST | L3 | behavior | behavior:lifecycleintegrity-004, behavior:lifecycleintegrity-104 |
| ALC-34 | 03_action-lifecycle.md | MUST NOT | L3 | behavior | behavior:lifecycleintegrity-005, behavior:lifecycleintegrity-105 |
| ALC-35 | 03_action-lifecycle.md | MUST | L3 | behavior | behavior:lifecycleintegrity-006, behavior:lifecycleintegrity-106 |
| ALC-36 | 03_action-lifecycle.md | MUST | L3 | behavior | behavior:lifecycleintegrity-007, behavior:lifecycleintegrity-107 |
| ALC-37 | 03_action-lifecycle.md | MUST | L3 | behavior | behavior:lifecycleintegrity-008, behavior:lifecycleintegrity-108 |
| CNF-1 | 04_confirmation.md | MUST NOT | L1 | behavior | behavior:clarification-010 |
| CNF-2 | 04_confirmation.md | MUST | L1 | behavior | behavior:confirmation-001, behavior:profile-001 |
| CNF-3 | 04_confirmation.md | MUST | L1 | behavior | behavior:confpolicy-002, behavior:confpolicy-102 |
| CNF-4 | 04_confirmation.md | MUST | L1 | behavior | behavior:confpolicy-003, behavior:confpolicy-103 |
| CNF-5 | 04_confirmation.md | MUST | L1 | behavior | behavior:confpolicy-004, behavior:confpolicy-104 |
| CNF-6 | 04_confirmation.md | MUST NOT | L1 | behavior | behavior:confpolicy-005, behavior:confpolicy-105 |
| CNF-7 | 04_confirmation.md | MUST | L1 | behavior | behavior:profile-001 |
| CNF-8 | 04_confirmation.md | MUST NOT | L1 | behavior | behavior:confpolicy-006, behavior:confpolicy-106 |
| CNF-9 | 04_confirmation.md | MUST | L1 | behavior | behavior:confirmation-001 |
| CNF-10 | 04_confirmation.md | MUST | L1 | behavior | behavior:confirmation-001 |
| CNF-11 | 04_confirmation.md | MUST | L1 | behavior | behavior:confirmation-001 |
| CNF-12 | 04_confirmation.md | MUST | L1 | behavior | behavior:confirmation-001 |
| CNF-13 | 04_confirmation.md | MUST NOT | L1 | behavior | behavior:conftoken-101 |
| CNF-14 | 04_confirmation.md | MUST | L1 | behavior | behavior:confirmation-002 |
| CNF-15 | 04_confirmation.md | MUST | L1 | behavior | behavior:confirmation-002 |
| CNF-16 | 04_confirmation.md | MUST | L1 | behavior | behavior:confirmation-003 |
| CNF-17 | 04_confirmation.md | MUST NOT | L1 | behavior | behavior:confirmation-002 |
| CNF-18 | 04_confirmation.md | MUST | L1 | behavior | behavior:confirmation-004 |
| CNF-19 | 04_confirmation.md | MUST | L1 | behavior | behavior:confirmation-005 |
| CNF-20 | 04_confirmation.md | MUST | L1 | behavior | behavior:confirmation-003 |
| CNF-21 | 04_confirmation.md | MUST | L1 | behavior | behavior:conftoken-002, behavior:conftoken-102 |
| CNF-22 | 04_confirmation.md | MUST | L1 | behavior | behavior:confirmation-003, behavior:confirmation-004 |
| CNF-23 | 04_confirmation.md | MUST | L1 | behavior | behavior:conftoken-003, behavior:conftoken-103 |
| CNF-24 | 04_confirmation.md | MUST | L1 | behavior | behavior:confirmation-006, behavior:confirmation-008 |
| CNF-25 | 04_confirmation.md | MUST | L1 | behavior | behavior:confirmation-006, behavior:confirmation-007 |
| CNF-26 | 04_confirmation.md | MUST NOT | L1 | behavior | behavior:confirmation-008 |
| CNF-27 | 04_confirmation.md | MUST | L1 | behavior | behavior:confirmation-008 |
| CNF-28 | 04_confirmation.md | MUST NOT | L1 | behavior | behavior:confirmation-008 |
| CNF-29 | 04_confirmation.md | MUST | L1 | behavior | behavior:confirmation-005 |
| CNF-30 | 04_confirmation.md | MUST | L1 | behavior | behavior:confirmation-005 |
| CNF-31 | 04_confirmation.md | MUST | L1 | behavior | behavior:confirmation-005 |
| CNF-32 | 04_confirmation.md | MAY | L3 | — | — |
| CNF-33 | 04_confirmation.md | MUST | L3 | behavior | behavior:confdual-001, behavior:confdual-101 |
| CNF-34 | 04_confirmation.md | MUST NOT | L3 | behavior | behavior:confdual-002, behavior:confdual-102 |
| CNF-35 | 04_confirmation.md | MUST | L1 | behavior | behavior:confpolicy-007, behavior:confpolicy-107 |
| CNF-36 | 04_confirmation.md | MUST NOT | L1 | behavior | behavior:confpolicy-008, behavior:confpolicy-108 |
| CNF-37 | 04_confirmation.md | MUST | L1 | behavior | behavior:confirmation-009 |
| CNF-38 | 04_confirmation.md | MUST | L1 | behavior | behavior:conftoken-004, behavior:conftoken-104 |
| CNF-39 | 04_confirmation.md | MUST | L1 | behavior | behavior:confirmation-006 |
| CNF-40 | 04_confirmation.md | MUST | L1 | behavior | behavior:confirmation-009 |
| CNF-41 | 04_confirmation.md | MUST NOT | L1 | behavior | behavior:confirmation-009 |
| CLR-1 | 05_clarification-mrtr.md | MUST | L2 | behavior | behavior:clarification-001, behavior:clarification-010 |
| CLR-2 | 05_clarification-mrtr.md | MUST | L2 | behavior | behavior:clarificationstate-001, behavior:clarificationstate-101 |
| CLR-3 | 05_clarification-mrtr.md | MUST NOT | L2 | behavior | behavior:clarification-011 |
| CLR-4 | 05_clarification-mrtr.md | MUST | L2 | behavior | behavior:clarification-001, behavior:clarification-010 |
| CLR-5 | 05_clarification-mrtr.md | MUST | L2 | behavior | behavior:clarification-007 |
| CLR-6 | 05_clarification-mrtr.md | MUST | L2 | behavior | behavior:clarification-001, behavior:clarification-002 |
| CLR-7 | 05_clarification-mrtr.md | MUST | L2 | behavior | behavior:clarification-001 |
| CLR-8 | 05_clarification-mrtr.md | MUST NOT | L2 | behavior | behavior:clarificationstate-002, behavior:clarificationstate-102 |
| CLR-9 | 05_clarification-mrtr.md | MUST NOT | L2 | behavior | behavior:clarification-006 |
| CLR-10 | 05_clarification-mrtr.md | MUST | L2 | behavior | behavior:clarification-002 |
| CLR-11 | 05_clarification-mrtr.md | MUST | L2 | behavior | behavior:clarification-002, behavior:clarification-003 |
| CLR-12 | 05_clarification-mrtr.md | MUST | L2 | behavior | behavior:clarification-009 |
| CLR-13 | 05_clarification-mrtr.md | MAY | L2 | — | — |
| CLR-14 | 05_clarification-mrtr.md | MUST | L2 | behavior | behavior:clarification-001 |
| CLR-15 | 05_clarification-mrtr.md | MUST | L2 | behavior | behavior:clarification-001 |
| CLR-16 | 05_clarification-mrtr.md | MUST | L2 | behavior | behavior:clarification-008 |
| CLR-17 | 05_clarification-mrtr.md | MUST NOT | L2 | behavior | behavior:clarification-008 |
| CLR-18 | 05_clarification-mrtr.md | MUST | L2 | behavior | behavior:clarification-004 |
| CLR-19 | 05_clarification-mrtr.md | MUST | L2 | behavior | behavior:clarification-005 |
| CLR-20 | 05_clarification-mrtr.md | MUST NOT | L2 | behavior | behavior:clarification-010 |
| CLR-21 | 05_clarification-mrtr.md | MUST NOT | L2 | behavior | behavior:clarification-010 |
| SCN-1 | 06_scenarios.md | MUST | L2 | behavior | behavior:scenario-001, behavior:scnshape-001 |
| SCN-2 | 06_scenarios.md | MUST | L2 | behavior | behavior:scenario-001, behavior:scnshape-001 |
| SCN-3 | 06_scenarios.md | MUST | L2 | behavior | behavior:scenario-001, behavior:scnforward-013, behavior:scnshape-001 |
| SCN-4 | 06_scenarios.md | MUST NOT | L2 | behavior | behavior:scenario-001, behavior:scnhostcode-014, behavior:scnnocode-003 |
| SCN-5 | 06_scenarios.md | MUST | L2 | behavior | behavior:scenario-001, behavior:scnshape-001 |
| SCN-6 | 06_scenarios.md | MUST | L2 | behavior | behavior:scenario-002, behavior:scnfailure-004 |
| SCN-7 | 06_scenarios.md | MUST | L2 | behavior | behavior:scenario-002, behavior:scnfailure-004 |
| SCN-8 | 06_scenarios.md | MUST | L2 | behavior | behavior:scenario-002, behavior:scnfailure-004 |
| SCN-9 | 06_scenarios.md | MUST NOT | L2 | behavior | behavior:scenario-002, behavior:scnforward-013, behavior:scnorder-002 |
| SCN-10 | 06_scenarios.md | MUST | L2 | behavior | behavior:scenario-002, behavior:scnfailure-004 |
| SCN-11 | 06_scenarios.md | MUST | L2 | behavior | behavior:scenario-002, behavior:scnfailure-004 |
| SCN-12 | 06_scenarios.md | MUST | L2 | behavior | behavior:scenario-003, behavior:scnfailure-004 |
| SCN-13 | 06_scenarios.md | MUST | L2 | behavior | behavior:scenario-003, behavior:scnhostcode-014, behavior:scnnocode-003 |
| SCN-14 | 06_scenarios.md | MUST | L2 | behavior | behavior:scenario-003, behavior:scnhostcode-014, behavior:scnnocode-003 |
| SCN-15 | 06_scenarios.md | MUST | L2 | behavior | behavior:scenario-003, behavior:scnhostcode-014, behavior:scnnocode-003 |
| SCN-16 | 06_scenarios.md | MUST | L2 | behavior | behavior:scenario-004, behavior:scnlimit-005, behavior:scnlimit-015 |
| SCN-17 | 06_scenarios.md | MUST | L2 | behavior | behavior:scenario-005, behavior:scnlimit-006 |
| SCN-18 | 06_scenarios.md | MUST | L2 | behavior | behavior:scenario-006, behavior:scnlimit-007 |
| SCN-19 | 06_scenarios.md | MUST | L2 | behavior | behavior:scenario-007, behavior:scnlimit-008 |
| SCN-20 | 06_scenarios.md | MUST | L2 | behavior | behavior:scenario-008, behavior:scnlimit-009 |
| SCN-21 | 06_scenarios.md | MUST | L2 | behavior | behavior:scenario-004, behavior:scenario-005, behavior:scenario-006, behavior:scenario-007, behavior:scenario-008, behavior:scnlimit-005, behavior:scnlimit-006, behavior:scnlimit-007, behavior:scnlimit-008, behavior:scnlimit-009, behavior:scnlimit-015 |
| SCN-22 | 06_scenarios.md | MUST | L2 | behavior | behavior:scenario-004, behavior:scenario-005, behavior:scenario-006, behavior:scenario-007, behavior:scenario-008, behavior:scnlimit-005, behavior:scnlimit-006, behavior:scnlimit-007, behavior:scnlimit-008, behavior:scnlimit-009, behavior:scnlimit-015 |
| SCN-23 | 06_scenarios.md | MUST | L2 | behavior | behavior:scenario-009, behavior:scncomp-010 |
| SCN-24 | 06_scenarios.md | MUST | L2 | behavior | behavior:scenario-009, behavior:scncomp-010 |
| SCN-25 | 06_scenarios.md | MUST | L2 | behavior | behavior:scenario-009, behavior:scncomp-010 |
| SCN-26 | 06_scenarios.md | MUST | L2 | behavior | behavior:scenario-010, behavior:scncomp-011 |
| SCN-27 | 06_scenarios.md | MUST | L2 | behavior | behavior:scenario-010, behavior:scncomp-011 |
| SCN-28 | 06_scenarios.md | MUST | L2 | behavior | behavior:scenario-009, behavior:scenario-010, behavior:scncomp-010 |
| SCN-29 | 06_scenarios.md | MUST NOT | L2 | behavior | behavior:scenario-010, behavior:scnoutcome-012 |
| SCN-30 | 06_scenarios.md | MUST | L2 | behavior | behavior:scenario-011, behavior:scnoutcome-012 |
| LOP-1 | 07_long-operations.md | MUST NOT | L2 | behavior | behavior:longop-001, behavior:lopsync-002, behavior:loptask-001 |
| LOP-2 | 07_long-operations.md | MUST | L2 | behavior | behavior:longop-001, behavior:lopsync-002, behavior:loptask-001 |
| LOP-3 | 07_long-operations.md | MUST | L2 | behavior | behavior:longop-001, behavior:lopsync-002, behavior:loptask-001 |
| LOP-4 | 07_long-operations.md | MUST NOT | L2 | behavior | behavior:longop-001, behavior:lopsync-002, behavior:loptask-001 |
| LOP-5 | 07_long-operations.md | MUST | L2 | behavior | behavior:longop-001, behavior:lopsync-002 |
| LOP-6 | 07_long-operations.md | MUST | L2 | behavior | behavior:longop-002, behavior:longop-102, behavior:lopsignal-003 |
| LOP-7 | 07_long-operations.md | MUST NOT | L2 | behavior | behavior:longop-002, behavior:longop-102, behavior:lopsignal-003 |
| LOP-8 | 07_long-operations.md | MUST | L2 | behavior | behavior:longop-002, behavior:longop-102, behavior:lopsignal-003 |
| LOP-9 | 07_long-operations.md | MUST | L2 | — | Not testable: канал уведомлений существует только у контрагента, объявившего его поддержку — у остального контрагента вектор подписки не исполняется |
| LOP-10 | 07_long-operations.md | MUST | L2 | behavior | behavior:longop-003, behavior:lopbind-004 |
| LOP-11 | 07_long-operations.md | MUST NOT | L2 | behavior | behavior:longop-003, behavior:lopbind-004 |
| LOP-12 | 07_long-operations.md | MUST | L2 | behavior | behavior:longop-004, behavior:lopguard-007 |
| LOP-13 | 07_long-operations.md | MUST | L2 | behavior | behavior:longop-004, behavior:lopbound-008 |
| LOP-14 | 07_long-operations.md | MUST | L2 | behavior | behavior:longop-004, behavior:lopbound-008 |
| LOP-15 | 07_long-operations.md | MUST | L2 | behavior | behavior:longop-005, behavior:loprecon-005 |
| LOP-16 | 07_long-operations.md | MUST | L2 | behavior | behavior:longop-005, behavior:loprecon-005 |
| LOP-17 | 07_long-operations.md | MUST NOT | L2 | behavior | behavior:longop-005, behavior:loprecon-005 |
| LOP-18 | 07_long-operations.md | MUST | L2 | behavior | behavior:longop-005, behavior:loprecon-005 |
| LOP-19 | 07_long-operations.md | MUST NOT | L2 | behavior | behavior:longop-005, behavior:loprecon-005 |
| LOP-20 | 07_long-operations.md | MUST | L2 | behavior | behavior:longop-006, behavior:lopcancel-006 |
| LOP-21 | 07_long-operations.md | MUST NOT | L2 | behavior | behavior:longop-006, behavior:lopcancel-006 |
| LOP-22 | 07_long-operations.md | MUST NOT | L2 | behavior | behavior:longop-006, behavior:lopcancel-006 |
| LOP-23 | 07_long-operations.md | MUST NOT | L2 | behavior | behavior:longop-006, behavior:lopcancel-006 |
| LOP-24 | 07_long-operations.md | MUST NOT | L2 | behavior | behavior:longop-006, behavior:lopguard-007 |
| LOP-25 | 07_long-operations.md | MUST NOT | L2 | behavior | behavior:longop-006, behavior:lopguard-007 |
| LOP-26 | 07_long-operations.md | MUST | L2 | behavior | behavior:longop-006, behavior:lopbound-008 |
| LOP-27 | 07_long-operations.md | MUST | L2 | behavior | behavior:longop-003, behavior:lopbind-004 |
| AOB-1 | 08_audit-observability.md | MUST | L3 | behavior | behavior:audit-001 |
| AOB-2 | 08_audit-observability.md | MUST | L3 | behavior | behavior:audit-001 |
| AOB-3 | 08_audit-observability.md | MUST NOT | L3 | behavior | behavior:audit-001 |
| AOB-4 | 08_audit-observability.md | MUST NOT | L3 | behavior | behavior:audit-001 |
| AOB-5 | 08_audit-observability.md | MUST NOT | L3 | behavior | behavior:audit-001 |
| AOB-6 | 08_audit-observability.md | MUST | L3 | behavior | behavior:audit-001 |
| AOB-7 | 08_audit-observability.md | MUST | L3 | behavior | behavior:audit-001 |
| AOB-8 | 08_audit-observability.md | MUST | L3 | behavior | behavior:audit-001 |
| AOB-9 | 08_audit-observability.md | MUST | L3 | behavior | behavior:audit-002 |
| AOB-10 | 08_audit-observability.md | MUST | L3 | behavior | behavior:audit-002 |
| AOB-11 | 08_audit-observability.md | MUST | L3 | behavior | behavior:audit-002 |
| AOB-12 | 08_audit-observability.md | MUST | L3 | behavior | behavior:audit-002 |
| AOB-13 | 08_audit-observability.md | MUST | L3 | behavior | behavior:audit-002 |
| AOB-14 | 08_audit-observability.md | MUST | L3 | behavior | behavior:audit-002 |
| AOB-15 | 08_audit-observability.md | MUST | L3 | behavior | behavior:audit-002 |
| AOB-16 | 08_audit-observability.md | MUST NOT | L3 | behavior | behavior:audit-002 |
| AOB-17 | 08_audit-observability.md | MAY | L3 | — | — |
| AOB-18 | 08_audit-observability.md | MUST NOT | L3 | behavior | behavior:audit-003 |
| AOB-19 | 08_audit-observability.md | MUST NOT | L3 | behavior | behavior:audit-003 |
| AOB-20 | 08_audit-observability.md | MUST | L3 | behavior | behavior:audit-003 |
| AOB-21 | 08_audit-observability.md | MUST | L3 | behavior | behavior:audit-003 |
| AOB-22 | 08_audit-observability.md | MUST NOT | L3 | behavior | behavior:audit-003 |
| AOB-23 | 08_audit-observability.md | MUST | L3 | behavior | behavior:audit-003 |
| AOB-24 | 08_audit-observability.md | MUST NOT | L3 | behavior | behavior:audit-003 |
| AOB-25 | 08_audit-observability.md | MUST | L3 | behavior | behavior:audit-004, behavior:audit-104 |
| AOB-26 | 08_audit-observability.md | MUST NOT | L3 | artifact | artifact:auditschema-001, artifact:auditschema-002 |
| AOB-27 | 08_audit-observability.md | MUST | L3 | behavior | behavior:audit-006, behavior:audit-106 |
| AOB-28 | 08_audit-observability.md | MUST NOT | L3 | behavior | behavior:audit-007, behavior:audit-107 |
| AOB-29 | 08_audit-observability.md | MUST | L3 | behavior | behavior:audit-008, behavior:audit-108 |
| AOB-30 | 08_audit-observability.md | MUST NOT | L3 | behavior | behavior:audit-009, behavior:audit-109 |
| AOB-31 | 08_audit-observability.md | MUST | L3 | behavior | behavior:audit-010, behavior:audit-110 |
| AOB-32 | 08_audit-observability.md | MUST | L1 | golden | golden:bmp-weird, golden:rfc8785-arrays, golden:rfc8785-french, golden:rfc8785-structures, golden:rfc8785-unicode, golden:rfc8785-values |
| AOB-33 | 08_audit-observability.md | MUST | L1 | golden | golden:bmp-weird, golden:rfc8785-french, golden:rfc8785-structures |
| AOB-34 | 08_audit-observability.md | MUST | L1 | golden | golden:bmp-weird |
| AOB-35 | 08_audit-observability.md | MUST | L1 | golden | golden:rfc8785-values |
| AOB-36 | 08_audit-observability.md | MUST | L1 | golden | golden:rfc8785-values |
| AOB-37 | 08_audit-observability.md | MUST | L1 | golden | golden:duplicate-keys |
| AOB-38 | 08_audit-observability.md | MUST NOT | L1 | golden | golden:bmp-weird, golden:rfc8785-french, golden:rfc8785-unicode |
| AOB-39 | 08_audit-observability.md | MUST | L1 | golden | golden:rfc8785-structures, golden:rfc8785-values |
| AOB-40 | 08_audit-observability.md | MUST | L1 | golden | golden:rfc8785-arrays, golden:rfc8785-structures, golden:rfc8785-values |
| AOB-41 | 08_audit-observability.md | MUST | L1 | golden | golden:bmp-weird, golden:rfc8785-arrays, golden:rfc8785-structures, golden:rfc8785-unicode, golden:rfc8785-values |
| AOB-42 | 08_audit-observability.md | MUST | L1 | golden | golden:bmp-weird, golden:rfc8785-arrays, golden:rfc8785-french, golden:rfc8785-structures, golden:rfc8785-unicode, golden:rfc8785-values |
| AOB-43 | 08_audit-observability.md | MUST | L1 | golden | golden:bmp-weird, golden:rfc8785-arrays, golden:rfc8785-french, golden:rfc8785-structures, golden:rfc8785-unicode, golden:rfc8785-values |
| AOB-44 | 08_audit-observability.md | MUST | L1 | golden | golden:bmp-weird, golden:rfc8785-arrays, golden:rfc8785-french, golden:rfc8785-structures, golden:rfc8785-unicode |
| IDE-1 | 09_idempotency-errors.md | MUST | L1 | behavior | behavior:idempotency-001 |
| IDE-2 | 09_idempotency-errors.md | MUST NOT | L1 | behavior | behavior:idempotency-001 |
| IDE-3 | 09_idempotency-errors.md | MUST | L1 | behavior | behavior:idempotency-001 |
| IDE-4 | 09_idempotency-errors.md | MUST | L1 | behavior | behavior:idempotency-001 |
| IDE-5 | 09_idempotency-errors.md | MUST | L1 | behavior | behavior:idempotency-007, behavior:idempotency-008 |
| IDE-6 | 09_idempotency-errors.md | MUST | L1 | golden | golden:key-composition-001, golden:key-composition-002, golden:key-composition-003 |
| IDE-7 | 09_idempotency-errors.md | MUST | L1 | golden | golden:key-composition-001, golden:key-composition-002, golden:key-composition-003 |
| IDE-8 | 09_idempotency-errors.md | MUST NOT | L1 | golden | golden:key-composition-001, golden:key-composition-003 |
| IDE-9 | 09_idempotency-errors.md | MUST | L1 | behavior | behavior:idempotency-001 |
| IDE-10 | 09_idempotency-errors.md | MUST | L1 | behavior | behavior:idempotency-001, behavior:idempotency-002, behavior:idempotency-003 |
| IDE-11 | 09_idempotency-errors.md | MUST | L1 | behavior | behavior:idempotency-001 |
| IDE-12 | 09_idempotency-errors.md | MUST | L1 | behavior | behavior:idempotency-002 |
| IDE-13 | 09_idempotency-errors.md | MUST NOT | L1 | behavior | behavior:idempotency-002 |
| IDE-14 | 09_idempotency-errors.md | MUST | L1 | behavior | behavior:idempotency-003 |
| IDE-15 | 09_idempotency-errors.md | MUST NOT | L1 | behavior | behavior:idempotency-003 |
| IDE-16 | 09_idempotency-errors.md | MUST | L1 | behavior | behavior:idempotency-004 |
| IDE-17 | 09_idempotency-errors.md | MUST | L1 | behavior, golden | behavior:idempotency-004, golden:key-composition-002, golden:key-composition-003 |
| IDE-18 | 09_idempotency-errors.md | MUST | L1 | behavior | behavior:idempotency-004 |
| IDE-19 | 09_idempotency-errors.md | MUST | L1 | behavior | behavior:idempotency-004 |
| IDE-20 | 09_idempotency-errors.md | MUST NOT | L1 | behavior | behavior:idempotency-004 |
| IDE-21 | 09_idempotency-errors.md | MUST | L1 | behavior | behavior:idempotency-004 |
| IDE-22 | 09_idempotency-errors.md | MUST | L1 | behavior | behavior:errors-001 |
| IDE-23 | 09_idempotency-errors.md | MUST | L1 | behavior | behavior:errors-001 |
| IDE-24 | 09_idempotency-errors.md | MUST NOT | L1 | behavior | behavior:errors-001 |
| IDE-25 | 09_idempotency-errors.md | MUST | L1 | behavior | behavior:errors-001 |
| IDE-26 | 09_idempotency-errors.md | MUST | L1 | behavior | behavior:errors-001 |
| IDE-27 | 09_idempotency-errors.md | MUST NOT | L1 | behavior | behavior:errors-001 |
| IDE-28 | 09_idempotency-errors.md | MUST | L1 | behavior | behavior:errors-001 |
| IDE-29 | 09_idempotency-errors.md | MUST | L1 | behavior | behavior:errors-001 |
| IDE-30 | 09_idempotency-errors.md | MUST | L1 | behavior | behavior:idempotency-005 |
| IDE-31 | 09_idempotency-errors.md | MUST | L1 | behavior | behavior:idempotency-003 |
| IDE-32 | 09_idempotency-errors.md | MUST NOT | L1 | behavior | behavior:idempotency-005 |
| IDE-33 | 09_idempotency-errors.md | MUST | L1 | behavior | behavior:idempotency-003 |
| IDE-34 | 09_idempotency-errors.md | MUST | L1 | behavior | behavior:idempotency-005 |
| IDE-35 | 09_idempotency-errors.md | MUST | L1 | behavior | behavior:idempotency-005 |
| IDE-36 | 09_idempotency-errors.md | MUST NOT | L1 | behavior | behavior:idempotency-005 |
| IDE-37 | 09_idempotency-errors.md | MUST | L1 | behavior | behavior:idempotency-005 |
| IDE-38 | 09_idempotency-errors.md | MUST NOT | L1 | behavior | behavior:grease-001, behavior:idempotency-006 |
| IDE-39 | 09_idempotency-errors.md | MUST | L1 | behavior | behavior:grease-001, behavior:grease-002, behavior:grease-004, behavior:idempotency-006, behavior:mustignore-001 |
| IDE-40 | 09_idempotency-errors.md | MUST NOT | L1 | behavior | behavior:grease-002, behavior:idempotency-006, behavior:mustignore-001 |
| IDE-41 | 09_idempotency-errors.md | MUST | L1 | behavior | behavior:idempotency-006 |
| IDE-42 | 09_idempotency-errors.md | MUST NOT | L1 | behavior | behavior:idempotency-006 |
| IDE-43 | 09_idempotency-errors.md | MUST NOT | L1 | behavior | behavior:grease-001, behavior:idempotency-006, behavior:mustignore-002 |
| IDE-44 | 09_idempotency-errors.md | MUST | L2 | — | Not testable: ревизия 2026-08 не вводит ни одного депрекированного core-значения — стадия наблюдаема только начиная с ревизии, депрецирующей первое такое значение |
| IDE-45 | 09_idempotency-errors.md | MUST | L2 | — | Not testable: та же причина, что IDE-44 — ни одно депрекированное core-значение в ревизии 2026-08 не существует, поведение по нему ненаблюдаемо до первой депрекации |
| IDE-46 | 09_idempotency-errors.md | MUST NOT | L2 | — | Not testable: та же причина, что IDE-44 — без депрекированного core-значения в корпусе нет производителя, которого можно поймать на его использовании |
| IDE-47 | 09_idempotency-errors.md | MUST | L2 | — | Not testable: та же причина, что IDE-44 — ревизия 2026-08 не несёт ни одного удалённого после окна core-значения, отвергать закрытой схемой пока нечего |
| IDE-48 | 09_idempotency-errors.md | MUST NOT | L2 | — | Not testable: адресат — автор будущей ревизии, вводящей удаление core-значения; ревизия 2026-08 сама ни одного значения не удаляет и депрекированных значений не имеет |
| SEC-1 | 10_security.md | MUST NOT | L1 | behavior | behavior:security-001, behavior:security-101 |
| SEC-2 | 10_security.md | MUST | L1 | behavior | behavior:security-002, behavior:security-102 |
| SEC-3 | 10_security.md | MUST | L1 | behavior | behavior:security-003, behavior:security-103 |
| SEC-4 | 10_security.md | MUST NOT | L1 | behavior | behavior:security-004, behavior:security-104 |
| SEC-5 | 10_security.md | MUST NOT | L1 | behavior | behavior:security-005, behavior:security-105 |
| SEC-6 | 10_security.md | MUST NOT | L1 | behavior | behavior:security-006, behavior:security-106 |
| SEC-7 | 10_security.md | MUST | L1 | behavior | behavior:security-007, behavior:security-107 |
| SEC-8 | 10_security.md | MUST NOT | L1 | behavior | behavior:security-008, behavior:security-108 |
| SEC-9 | 10_security.md | MUST | L1 | behavior | behavior:security-009, behavior:security-109 |
| SEC-10 | 10_security.md | MUST NOT | L1 | behavior | behavior:security-010, behavior:security-110 |
| SEC-11 | 10_security.md | MUST NOT | L1 | behavior | behavior:security-011, behavior:security-111 |
| SEC-12 | 10_security.md | MUST | L1 | behavior | behavior:security-012, behavior:security-112 |
| SEC-13 | 10_security.md | MUST | L1 | behavior | behavior:security-013, behavior:security-113 |
| SEC-14 | 10_security.md | MUST | L1 | behavior | behavior:security-014, behavior:security-114 |
| SEC-15 | 10_security.md | MUST NOT | L1 | behavior | behavior:security-015, behavior:security-115 |
| SEC-16 | 10_security.md | MUST | L1 | behavior | behavior:security-016, behavior:security-116 |
| SEC-17 | 10_security.md | MUST NOT | L1 | artifact | artifact:securityauthoring-001, artifact:securityauthoring-002 |
| SEC-18 | 10_security.md | MUST NOT | L1 | behavior | behavior:security-018, behavior:security-118 |
| SEC-19 | 10_security.md | MUST NOT | L1 | behavior | behavior:security-019, behavior:security-119 |
| CFH-1 | 11_conformance-harness.md | MUST | L1 | harness | harness:hseq-001 |
| CFH-2 | 11_conformance-harness.md | MUST NOT | L1 | artifact | artifact:cfhprotocol-001 |
| CFH-3 | 11_conformance-harness.md | MUST | L1 | harness | harness:hnostop-001, harness:hrunfirst-001, harness:hseq-001 |
| CFH-4 | 11_conformance-harness.md | MUST | L1 | harness | harness:hseq-001, harness:hverbad-001 |
| CFH-5 | 11_conformance-harness.md | MUST | L1 | artifact | artifact:cfhprotocol-001 |
| CFH-6 | 11_conformance-harness.md | MUST | L1 | harness | harness:hunknown-001 |
| CFH-7 | 11_conformance-harness.md | MUST | L1 | harness | harness:hseq-001 |
| CFH-8 | 11_conformance-harness.md | MUST NOT | L1 | harness | harness:hseq-001 |
| CFH-9 | 11_conformance-harness.md | MUST | L1 | harness | harness:hseq-001 |
| CFH-10 | 11_conformance-harness.md | MUST NOT | L1 | artifact | artifact:cfhprotocol-001 |
| CFH-11 | 11_conformance-harness.md | MUST | L1 | harness | harness:hnotest-001, harness:hnotestbad-001 |
| CFH-12 | 11_conformance-harness.md | MUST NOT | L1 | harness | harness:hseq-001 |
| CFH-13 | 11_conformance-harness.md | MUST | L1 | harness | harness:hseq-001 |
| CFH-14 | 11_conformance-harness.md | MUST | L1 | artifact | artifact:cfhtrace-001 |
| CFH-15 | 11_conformance-harness.md | MUST | L1 | artifact | artifact:cfhindex-001, artifact:cfhindex-002 |
| CFH-16 | 11_conformance-harness.md | MUST NOT | L1 | artifact | artifact:cfhtrace-001 |
| CFH-17 | 11_conformance-harness.md | MUST | L1 | artifact | artifact:cfhseverity-001 |
| CFH-18 | 11_conformance-harness.md | MUST NOT | L1 | artifact | artifact:cfhseverity-001 |
| CFH-19 | 11_conformance-harness.md | MUST | L1 | artifact | artifact:cfhseverity-001 |
| CFH-20 | 11_conformance-harness.md | MUST | L1 | artifact | artifact:ovrnotest-001, artifact:ovrnotest-002 |
| CFH-21 | 11_conformance-harness.md | MUST NOT | L1 | artifact | artifact:cfhseverity-001 |
| CFH-22 | 11_conformance-harness.md | MUST | L1 | artifact | artifact:cfhbaseline-004 |
| CFH-23 | 11_conformance-harness.md | MUST | L1 | artifact | artifact:cfhbaseline-004 |
| CFH-24 | 11_conformance-harness.md | MUST | L1 | artifact | artifact:cfhbaseline-001, artifact:cfhbaseline-101 |
| CFH-25 | 11_conformance-harness.md | MUST | L1 | artifact | artifact:cfhbaseline-002 |
| CFH-26 | 11_conformance-harness.md | MUST NOT | L1 | artifact | artifact:cfhbaseline-003, artifact:cfhbaseline-103 |
| CFH-27 | 11_conformance-harness.md | MUST | L1 | artifact | artifact:cfhci-001 |
| CFH-28 | 11_conformance-harness.md | MUST | L1 | artifact | artifact:cfhauthor-001, artifact:cfhauthor-002 |
| CFH-29 | 11_conformance-harness.md | MUST | L1 | artifact | artifact:cfhci-001 |
| CFH-30 | 11_conformance-harness.md | MUST | L1 | artifact | artifact:cfhci-001 |
| CFH-31 | 11_conformance-harness.md | MUST | L1 | artifact | artifact:cfhci-001 |
| CFH-32 | 11_conformance-harness.md | MUST NOT | L1 | artifact | artifact:cfhci-001 |
| CFH-33 | 11_conformance-harness.md | MUST NOT | L1 | artifact | artifact:cfhcodegen-001 |
| CFH-34 | 11_conformance-harness.md | MUST NOT | L1 | artifact | artifact:cfhcodegen-001 |
| CFH-35 | 11_conformance-harness.md | MUST | L1 | harness | harness:hgolden-001 |
| CFH-36 | 11_conformance-harness.md | MUST | L1 | harness | harness:hgolden-001 |
| CFH-37 | 11_conformance-harness.md | MUST NOT | L1 | harness | harness:hgolden-001 |
| CFH-38 | 11_conformance-harness.md | MUST | L1 | harness | harness:hgolden-001, harness:hgoldenbadorder-001 |
| CFH-39 | 11_conformance-harness.md | MUST | L1 | harness | harness:hgolden-001 |
| CFH-40 | 11_conformance-harness.md | MUST NOT | L1 | harness | harness:hgoldenctrl-001 |
| CFH-41 | 11_conformance-harness.md | MUST | L1 | harness | harness:hgoldenctrl-001 |
| CFH-42 | 11_conformance-harness.md | MUST | L1 | harness | harness:hgolden-001 |
| CFH-43 | 11_conformance-harness.md | MUST | L1 | harness | harness:hgolden-001 |
| CFH-44 | 11_conformance-harness.md | MUST | L1 | harness | harness:hgolden-001 |
| CFH-45 | 11_conformance-harness.md | MUST | L1 | harness | harness:hrevmis-001 |
| CFH-46 | 11_conformance-harness.md | MUST | L1 | harness | harness:hrevmis-001 |
| CFH-47 | 11_conformance-harness.md | MUST | L1 | harness | harness:hrevmis-001 |
| CFH-48 | 11_conformance-harness.md | MUST | L1 | artifact, behavior | behavior:grease-003, artifact:grease-001 |
| CFH-49 | 11_conformance-harness.md | MUST | L1 | artifact, behavior | behavior:grease-003, artifact:grease-001 |
| CFH-50 | 11_conformance-harness.md | MUST | L1 | artifact | artifact:cfhgrease-002 |
| CFH-51 | 11_conformance-harness.md | MUST | L1 | artifact | artifact:cfhgrease-001, artifact:cfhgrease-101 |
| CFH-52 | 11_conformance-harness.md | MUST | L1 | artifact | artifact:cfhreport-002 |
| CFH-53 | 11_conformance-harness.md | MUST | L1 | artifact | artifact:cfhreport-001, artifact:cfhreport-101 |
| CFH-54 | 11_conformance-harness.md | MUST NOT | L1 | artifact | artifact:cfhreport-003 |
| CFH-55 | 11_conformance-harness.md | MUST | L1 | harness | harness:hmcpcode-001, harness:hmcpcodebad-001 |
