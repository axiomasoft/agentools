---
type: analysis
tags: [agentools, audit, ecosystem, planning-input]
created: 2026-10-30
status: planning-input
source_file: ../../../../edufy/audits/2026-10-30-ecosystem/source/edufy-next-eco.md
source_lines: "6385–7761"
---

# AgenTools: полный технический справочник RFC

Вход для будущего плана Opus. Выводы диалога сведены без повторов; это не план исполнения и не подтверждение реализации. Общие поправки и зависимости: [Edufy-Ecosystem-Audit-Map-2026-10-30](../../../../edufy/audits/2026-10-30-ecosystem/00-ECOSYSTEM-MAP.md).

## Как читать справочник

Содержательная техническая часть финального RFC перенесена из исходника: полные деревья base packages/extensions, все группы таблиц, пример tenant-safe DDL, публичные PHP-контракты, handler Edufy, ActionDefinition и profile manifest. Эти примеры **не проверены на существующем API** и **не являются готовыми миграциями**.

Обязательные поправки перед планированием:

- Старые `spec/`, `fixtures/`, `conformance/`, `php/`, `dart/` сохраняют отдельные обязательства. Новый `protocol/v1` — integration proposal, не замена dated spec `2026-09`.
- Имена Composer `axioma/*`, namespace `Axioma\Agentools`, таблицы `agt_*`, числовые limits и сигнатуры handlers — примеры. Текущий PHP SDK использует `agentools/sdk` / `AgenTools\`; совместимость и private/proprietary release policy надо решить отдельно.
- Упоминание таблиц не разрешает дублировать transcript, AiCore tasks, общий usage store, entitlement или money ledger. Projection/checkpoint/technical cache должны быть названы явно.
- `agt_knowledge_materials` может быть проекцией owner Library; `agt_eval_*` — предложение технических проверок в AgenTools, не обязательная зависимость от отдельного EvalKit; `agt_budget_*` — технический admission через BudgetPort.
- Точный DDL дополняется nullability, checks, indexes, retention, optimistic concurrency и active-run uniqueness после аудита. Пример SQL охватывает только две таблицы.
- Существование класса в этом дереве не является причиной создать пустую абстракцию. Отдельные расширения вводятся по работающему сценарию.


<!-- source-lines: 6385–7137 -->

## Корень

```text
agentools/
├── README.md
├── LICENSE
├── CHANGELOG.md
├── SECURITY.md
├── CONTRIBUTING.md
├── composer.json
├── composer.lock
├── phpunit.xml
├── phpstan.neon
├── rector.php
├── pint.json
├── deptrac.yaml
├── .editorconfig
├── .gitattributes
├── .gitignore
├── .github/
│   ├── CODEOWNERS
│   ├── PULL_REQUEST_TEMPLATE.md
│   └── workflows/
│       ├── quality.yml
│       ├── integration.yml
│       ├── contracts.yml
│       ├── sdk-compatibility.yml
│       ├── security.yml
│       └── release.yml
├── docs/
│   ├── index.md
│   ├── terminology.md
│   ├── architecture.md
│   ├── package-boundaries.md
│   ├── ownership.md
│   ├── data-model.md
│   ├── state-machines.md
│   ├── recovery.md
│   ├── profiles.md
│   ├── context.md
│   ├── models.md
│   ├── actions.md
│   ├── security.md
│   ├── privacy.md
│   ├── observability.md
│   ├── installation.md
│   ├── operations.md
│   ├── compatibility.md
│   ├── migration-from-botkit.md
│   ├── migration-from-agentools.md
│   ├── adr/
│   │   ├── 0001-single-platform.md
│   │   ├── 0002-pure-core.md
│   │   ├── 0003-single-assistant-loop.md
│   │   ├── 0004-action-journal.md
│   │   ├── 0005-profile-composition.md
│   │   ├── 0006-context-provenance.md
│   │   ├── 0007-model-adapters.md
│   │   ├── 0008-multitenancy.md
│   │   ├── 0009-payload-retention.md
│   │   ├── 0010-conversation-ownership.md
│   │   └── 0011-realtime-execution.md
│   └── diagrams/
│       ├── packages.mmd
│       ├── text-turn.mmd
│       ├── action-confirmation.mmd
│       ├── action-recovery.mmd
│       ├── profile-publication.mmd
│       └── voice-turn.mmd
├── protocol/
│   └── v1/
│       ├── README.md
│       ├── execution-context.schema.json
│       ├── turn-request.schema.json
│       ├── run-event.schema.json
│       ├── action-request.schema.json
│       ├── action-outcome.schema.json
│       ├── confirmation.schema.json
│       ├── model-usage.schema.json
│       ├── delivery-receipt.schema.json
│       ├── error.schema.json
│       └── fixtures/
│           ├── turn-text.json
│           ├── turn-voice.json
│           ├── action-read.json
│           ├── action-confirmation.json
│           ├── action-unknown.json
│           └── delivery-interrupted.json
├── packages/
│   ├── agentools/
│   ├── core/
│   ├── postgres/
│   ├── laravel-ai/
│   ├── laravel/
│   └── testing/
├── extensions/
│   ├── knowledge/
│   ├── chatom/
│   ├── loqum/
│   ├── filament/
│   ├── evaluation/
│   └── scheduler/
├── examples/
│   ├── support-assistant/
│   │   ├── README.md
│   │   ├── SupportAssistant.php
│   │   ├── SupportContextProvider.php
│   │   ├── CreateTicketHandler.php
│   │   └── profile.yaml
│   └── edufy-integration/
│       ├── README.md
│       ├── TeacherAssistant.php
│       ├── LessonContextProvider.php
│       ├── GetLessonMaterialHandler.php
│       └── profile.yaml
├── tests/
│   ├── Architecture/
│   │   ├── PackageBoundariesTest.php
│   │   ├── CorePurityTest.php
│   │   └── ForbiddenDependenciesTest.php
│   ├── EndToEnd/
│   │   ├── TextConversationTest.php
│   │   ├── ConfirmationRecoveryTest.php
│   │   ├── ProviderFailoverTest.php
│   │   └── TenantIsolationTest.php
│   └── Bootstrap.php
└── tools/
    ├── check-boundaries.php
    ├── check-protocol.php
    ├── check-package-manifests.php
    ├── check-sdk-compatibility.php
    ├── run-fault-scenarios.php
    └── release-package.php
```

**Существующий протокол Agentools нельзя автоматически заменить этим `v1`.** Сначала нужно установить реальные опубликованные обязательства и спроектировать совместимость. Здесь `v1` обозначает проектируемый общий integration protocol.

## Чистое ядро

```text
packages/core/
├── composer.json
├── README.md
├── src/
│   ├── Common/
│   │   ├── ExecutionContext.php
│   │   ├── TenantId.php
│   │   ├── ActorRef.php
│   │   ├── ResourceRef.php
│   │   ├── PayloadRef.php
│   │   ├── Correlation.php
│   │   ├── Clock.php
│   │   ├── IdGenerator.php
│   │   ├── TransactionBoundary.php
│   │   ├── PayloadStore.php
│   │   ├── EventPublisher.php
│   │   ├── DomainEvent.php
│   │   ├── ErrorCode.php
│   │   ├── DomainException.php
│   │   └── ConcurrencyException.php
│   ├── Profiles/
│   │   ├── AssistantDefinition.php
│   │   ├── AssistantRegistry.php
│   │   ├── AssistantInstance.php
│   │   ├── ProfileVersion.php
│   │   ├── ProfileLayer.php
│   │   ├── ProfileBundle.php
│   │   ├── ProfileSource.php
│   │   ├── ProfileRepository.php
│   │   ├── ProfileCompiler.php
│   │   ├── ProfileResolver.php
│   │   ├── ProfileValidator.php
│   │   ├── ProfilePublicationService.php
│   │   ├── ProfileConflict.php
│   │   ├── MergeRule.php
│   │   ├── MergeRuleRegistry.php
│   │   ├── ProvenanceEntry.php
│   │   ├── SkillDefinition.php
│   │   ├── SkillVersion.php
│   │   ├── SkillRepository.php
│   │   └── SkillSelector.php
│   ├── Context/
│   │   ├── ContextRequest.php
│   │   ├── ContextPlan.php
│   │   ├── ContextSnapshot.php
│   │   ├── ContextBlock.php
│   │   ├── ContextProvider.php
│   │   ├── ContextProviderRegistry.php
│   │   ├── ContextCompiler.php
│   │   ├── ContextSnapshotRepository.php
│   │   ├── ContextPolicy.php
│   │   ├── ContextBudget.php
│   │   ├── TokenBudgeter.php
│   │   ├── ContextDeduplicator.php
│   │   ├── ContextManifest.php
│   │   ├── TrustClass.php
│   │   ├── HistorySource.php
│   │   ├── HistorySlice.php
│   │   ├── EvidenceSource.php
│   │   ├── EvidenceFragment.php
│   │   ├── WorkingState.php
│   │   ├── SummaryVersion.php
│   │   ├── SummaryRepository.php
│   │   ├── SummaryPlanner.php
│   │   ├── MemorySource.php
│   │   └── ContextInvalidator.php
│   ├── Models/
│   │   ├── ModelRequest.php
│   │   ├── ModelResponse.php
│   │   ├── ModelMessage.php
│   │   ├── ContentPart.php
│   │   ├── ModelEvent.php
│   │   ├── ModelEventStream.php
│   │   ├── ModelTransport.php
│   │   ├── ModelTransportRegistry.php
│   │   ├── ModelDeployment.php
│   │   ├── DeploymentRepository.php
│   │   ├── CapabilitySet.php
│   │   ├── CapabilityRequirement.php
│   │   ├── ModelPolicy.php
│   │   ├── ModelRouter.php
│   │   ├── RoutingDecision.php
│   │   ├── TokenCounter.php
│   │   ├── Usage.php
│   │   ├── FinishReason.php
│   │   ├── ProviderFailure.php
│   │   ├── OperationalOverride.php
│   │   ├── CacheIntent.php
│   │   ├── CacheBinding.php
│   │   └── ProviderCache.php
│   ├── Actions/
│   │   ├── ActionDefinition.php
│   │   ├── ActionRegistry.php
│   │   ├── ActionRequest.php
│   │   ├── Action.php
│   │   ├── ActionStatus.php
│   │   ├── ActionOutcome.php
│   │   ├── ActionEvent.php
│   │   ├── ActionReducer.php
│   │   ├── ActionRepository.php
│   │   ├── ActionExecutor.php
│   │   ├── ActionDispatcher.php
│   │   ├── ActionWorker.php
│   │   ├── ActionHandler.php
│   │   ├── ActionExecution.php
│   │   ├── ActionInputValidator.php
│   │   ├── ActionAuthorization.php
│   │   ├── ActionPolicy.php
│   │   ├── EffectClass.php
│   │   ├── EffectRequest.php
│   │   ├── EffectOutcome.php
│   │   ├── EffectExecutor.php
│   │   ├── EffectRepository.php
│   │   ├── Reconciler.php
│   │   ├── ReconciliationResult.php
│   │   ├── RetryPolicy.php
│   │   ├── ConfirmationRequest.php
│   │   ├── ConfirmationDecision.php
│   │   ├── ConfirmationService.php
│   │   └── ConfirmationRepository.php
│   ├── Runtime/
│   │   ├── AssistantSession.php
│   │   ├── SessionRepository.php
│   │   ├── Turn.php
│   │   ├── TurnRequest.php
│   │   ├── TurnRepository.php
│   │   ├── Run.php
│   │   ├── RunStatus.php
│   │   ├── RunRepository.php
│   │   ├── RunStep.php
│   │   ├── RunCheckpoint.php
│   │   ├── ModelInvocation.php
│   │   ├── InvocationRepository.php
│   │   ├── SubmitTurn.php
│   │   ├── AdvanceRun.php
│   │   ├── ResumeRun.php
│   │   ├── CancelRun.php
│   │   ├── RunRecovery.php
│   │   ├── RunLimits.php
│   │   ├── ToolProposal.php
│   │   ├── ToolCatalog.php
│   │   ├── ToolResultView.php
│   │   ├── ResponseDraft.php
│   │   ├── ResponseFinalizer.php
│   │   ├── DeliveryPort.php
│   │   ├── DeliveryReceipt.php
│   │   ├── AdmissionPolicy.php
│   │   ├── DisclosurePolicy.php
│   │   ├── BudgetPort.php
│   │   ├── BudgetReservation.php
│   │   ├── UsageRecorder.php
│   │   ├── RunObserver.php
│   │   └── CancellationToken.php
│   └── Security/
│       ├── MandatoryGuard.php
│       ├── GuardChain.php
│       ├── PolicyDecision.php
│       ├── DataClassification.php
│       ├── RedactionPolicy.php
│       ├── SecretReference.php
│       ├── SecretResolver.php
│       └── RetentionPolicy.php
└── tests/
    ├── Profiles/
    │   ├── ProfileCompilerTest.php
    │   ├── ProfileConflictsTest.php
    │   └── SafetyFloorTest.php
    ├── Context/
    │   ├── TokenBudgeterTest.php
    │   ├── ContextDeduplicatorTest.php
    │   └── ContextTrustTest.php
    ├── Actions/
    │   ├── ActionReducerTest.php
    │   ├── ConfirmationBindingTest.php
    │   └── UnknownOutcomeTest.php
    ├── Runtime/
    │   ├── AdvanceRunTest.php
    │   ├── RunRecoveryTest.php
    │   └── CancellationTest.php
    └── Models/
        ├── ModelRouterTest.php
        └── UsageAccountingTest.php
```


### Почему Core достаточно крупный

Чистое ядро — не обязательно маленькая папка с пятью интерфейсами. В нём находится **существенная логика платформы**, но нет зависимостей от способов хранения и доставки.

При этом:

- `ProfileCompiler` не знает SQL.
- `ActionReducer` не знает очереди.
- `ModelRouter` не знает SDK.
- `AdvanceRun` не знает Chatom.
- `TokenBudgeter` не знает школу.


## PostgreSQL

```text
packages/postgres/
├── composer.json
├── README.md
├── resources/
│   └── migrations/
│       ├── 001_profiles.sql
│       ├── 002_models.sql
│       ├── 003_runtime.sql
│       ├── 004_actions.sql
│       ├── 005_usage.sql
│       └── 006_integration.sql
├── src/
│   ├── Connection/
│   │   ├── ConnectionFactory.php
│   │   ├── TransactionManager.php
│   │   └── SqlErrorMapper.php
│   ├── Profiles/
│   │   ├── PgProfileRepository.php
│   │   └── PgSkillRepository.php
│   ├── Context/
│   │   ├── PgContextSnapshotRepository.php
│   │   └── PgSummaryRepository.php
│   ├── Models/
│   │   ├── PgDeploymentRepository.php
│   │   ├── PgCacheBindingRepository.php
│   │   └── PgOperationalOverrideRepository.php
│   ├── Runtime/
│   │   ├── PgSessionRepository.php
│   │   ├── PgTurnRepository.php
│   │   ├── PgRunRepository.php
│   │   ├── PgInvocationRepository.php
│   │   └── PgDeliveryReceiptRepository.php
│   ├── Actions/
│   │   ├── PgActionRepository.php
│   │   ├── PgEffectRepository.php
│   │   ├── PgConfirmationRepository.php
│   │   └── PgActionClaimStore.php
│   ├── Usage/
│   │   ├── PgUsageRecorder.php
│   │   └── PgBudgetStore.php
│   ├── Integration/
│   │   ├── PgOutbox.php
│   │   ├── PgInbox.php
│   │   ├── PgAuditWriter.php
│   │   ├── PgArtifactCatalog.php
│   │   └── OutboxRelay.php
│   └── Mapping/
│       ├── JsonCodec.php
│       ├── DateTimeCodec.php
│       ├── IdentifierCodec.php
│       └── PayloadReferenceCodec.php
└── tests/
    ├── RepositoryConformanceTest.php
    ├── ConcurrentActionClaimTest.php
    ├── TransactionRollbackTest.php
    ├── OutboxRecoveryTest.php
    ├── TenantForeignKeyTest.php
    └── MigrationTest.php
```

Здесь намеренно выбран PostgreSQL как основной production storage. Поддерживать одновременно несколько SQL-диалектов в первой версии — дополнительная работа без доказанной пользы.

**Eloquent не является доменной моделью.** Административные read models можно построить отдельно.

## Мост к `laravel/ai`

```text
packages/laravel-ai/
├── composer.json
├── README.md
├── src/
│   ├── LaravelAiTransport.php
│   ├── LaravelAiTokenCounter.php
│   ├── LaravelAiCapabilityProbe.php
│   ├── LaravelAiCacheAdapter.php
│   ├── LaravelAiRequestMapper.php
│   ├── LaravelAiResponseMapper.php
│   ├── LaravelAiStreamMapper.php
│   ├── LaravelAiUsageMapper.php
│   ├── LaravelAiErrorMapper.php
│   ├── SdkCompatibility.php
│   └── UnsupportedCapability.php
└── tests/
    ├── RequestMappingTest.php
    ├── ResponseMappingTest.php
    ├── ToolProposalConformanceTest.php
    ├── StreamLifecycleTest.php
    ├── CacheSemanticsTest.php
    ├── UsageMappingTest.php
    └── SupportedSdkVersionTest.php
```

Важный момент: эти имена — **наши адаптеры**, а не классы, которые якобы существуют в SDK.

Точную интеграцию следует писать только после проверки выбранной версии `laravel/ai`.

## Laravel-интеграция

```text
packages/laravel/
├── composer.json
├── README.md
├── config/
│   └── agentools.php
├── routes/
│   └── api.php
├── src/
│   ├── AgentoolsServiceProvider.php
│   ├── AgentoolsManager.php
│   ├── Facades/
│   │   └── Agentools.php
│   ├── Boot/
│   │   ├── RegisterDefinitions.php
│   │   ├── ValidateConfiguration.php
│   │   └── ValidateAdapterCompatibility.php
│   ├── Context/
│   │   ├── ExecutionContextFactory.php
│   │   ├── HttpExecutionContextFactory.php
│   │   ├── JobExecutionContextFactory.php
│   │   └── ScopedExecutionContext.php
│   ├── Jobs/
│   │   ├── AdvanceRunJob.php
│   │   ├── ExecuteActionJob.php
│   │   ├── ReconcileActionJob.php
│   │   ├── RelayOutboxJob.php
│   │   └── DeliverResponseJob.php
│   ├── Commands/
│   │   ├── DoctorCommand.php
│   │   ├── MigrateCommand.php
│   │   ├── RecoverCommand.php
│   │   ├── ReconcileCommand.php
│   │   ├── RelayCommand.php
│   │   ├── PruneCommand.php
│   │   ├── WarmCacheCommand.php
│   │   └── InspectRunCommand.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── TurnController.php
│   │   │   ├── RunController.php
│   │   │   ├── ConfirmationController.php
│   │   │   └── RunStreamController.php
│   │   ├── Requests/
│   │   │   ├── SubmitTurnRequest.php
│   │   │   └── DecideConfirmationRequest.php
│   │   ├── Middleware/
│   │   │   ├── ResolveExecutionContext.php
│   │   │   └── AuthorizeAgentoolsAccess.php
│   │   └── Resources/
│   │       ├── RunResource.php
│   │       └── ActionResource.php
│   ├── Security/
│   │   ├── LaravelSecretResolver.php
│   │   └── HostPolicyAdapter.php
│   ├── Storage/
│   │   └── LaravelPayloadStore.php
│   ├── Observability/
│   │   ├── LaravelRunObserver.php
│   │   ├── StructuredLogWriter.php
│   │   └── MetricsRecorder.php
│   └── Events/
│       ├── RunAccepted.php
│       ├── RunCompleted.php
│       ├── RunFailed.php
│       └── ActionNeedsResolution.php
└── tests/
    ├── ServiceProviderTest.php
    ├── ScopedContextResetTest.php
    ├── HttpAuthorizationTest.php
    ├── QueueRecoveryTest.php
    ├── StreamingDisclosureTest.php
    └── CommandTest.php
```

HTTP routes должны быть **выключены по умолчанию** или явно монтироваться приложением. Установка библиотеки не должна сама публиковать административное API.

## Тестовый пакет

```text
packages/testing/
├── composer.json
├── README.md
├── src/
│   ├── FakeClock.php
│   ├── SequenceIdGenerator.php
│   ├── FakeModelTransport.php
│   ├── ScriptedModelResponse.php
│   ├── FakeActionHandler.php
│   ├── FakeDeliveryPort.php
│   ├── InMemoryPayloadStore.php
│   ├── InMemoryRepositories.php
│   ├── RecordingObserver.php
│   ├── FaultInjector.php
│   ├── ContractAssertions.php
│   └── Conformance/
│       ├── ModelTransportContract.php
│       ├── ActionRepositoryContract.php
│       ├── RunRepositoryContract.php
│       ├── DeliveryPortContract.php
│       └── ContextProviderContract.php
└── tests/
    ├── FakeTransportTest.php
    └── FaultInjectorTest.php
```

Fake должен заменять внешние зависимости, но не пропускать обязательные guards и lifecycle.

## Метапакет

```text
packages/agentools/
├── composer.json
└── README.md
```

В нём действительно больше ничего не нужно.

## Структура расширений

## Knowledge

```text
extensions/knowledge/
├── composer.json
├── README.md
├── config/
│   └── agentools-knowledge.php
├── resources/
│   └── migrations/
│       └── 001_knowledge.sql
├── src/
│   ├── KnowledgeServiceProvider.php
│   ├── Contracts/
│   │   ├── DocumentSource.php
│   │   ├── DocumentExtractor.php
│   │   ├── ExtractionValidator.php
│   │   ├── EmbeddingTransport.php
│   │   ├── SearchIndex.php
│   │   ├── MaterialAccessPolicy.php
│   │   └── KnowledgeRepository.php
│   ├── Domain/
│   │   ├── MaterialVersion.php
│   │   ├── DocumentNode.php
│   │   ├── SourceFragment.php
│   │   ├── KnowledgeCard.php
│   │   ├── CorpusRelease.php
│   │   ├── ProcessingRun.php
│   │   └── ValidationReport.php
│   ├── Application/
│   │   ├── RegisterMaterial.php
│   │   ├── ProcessMaterial.php
│   │   ├── ValidateDerivatives.php
│   │   ├── PublishCorpus.php
│   │   ├── RetrieveEvidence.php
│   │   └── InvalidateMaterial.php
│   ├── Processing/
│   │   ├── BookProcessingPlan.php
│   │   ├── StructureExtractionStep.php
│   │   ├── FragmentationStep.php
│   │   ├── NavigationSummaryStep.php
│   │   ├── KnowledgeCardStep.php
│   │   └── EvidenceValidationStep.php
│   ├── Retrieval/
│   │   ├── HybridRetriever.php
│   │   ├── RetrievalFilter.php
│   │   ├── Reranker.php
│   │   ├── EvidenceDeduplicator.php
│   │   └── KnowledgeEvidenceSource.php
│   └── Infrastructure/
│       ├── PgKnowledgeRepository.php
│       └── PgSearchIndex.php
└── tests/
    ├── SourcePreservationTest.php
    ├── RestrictedEvidenceTest.php
    ├── CorpusPublicationTest.php
    ├── DerivativeInvalidationTest.php
    └── RetrievalConformanceTest.php
```

Специализированные OCR/PDF engines подключаются реализациями `DocumentExtractor`. Не нужно включать тяжёлую инфраструктуру в Core.

## Chatom

```text
extensions/chatom/
├── composer.json
├── README.md
├── src/
│   ├── ChatomServiceProvider.php
│   ├── ChatomHistorySource.php
│   ├── ChatomDeliveryPort.php
│   ├── ChatomMessageMapper.php
│   ├── ChatomTriggerConsumer.php
│   ├── ChatomVisibilityAdapter.php
│   └── ChatomInvalidationConsumer.php
└── tests/
    ├── TriggerDeduplicationTest.php
    ├── ResponseDeliveryTest.php
    ├── VisibilityTest.php
    └── EditedMessageInvalidationTest.php
```


## Loqum

```text
extensions/loqum/
├── composer.json
├── README.md
├── src/
│   ├── LoqumServiceProvider.php
│   ├── VoiceTurnMapper.php
│   ├── RealtimeSessionBinding.php
│   ├── RealtimeExecutionPlan.php
│   ├── RealtimeExecutionPlanIssuer.php
│   ├── VoiceResponseDelivery.php
│   ├── PlayoutReceiptConsumer.php
│   ├── InterruptionPolicy.php
│   └── LoqumEventConsumer.php
└── tests/
    ├── FinalTranscriptDeduplicationTest.php
    ├── BargeInTest.php
    ├── PlayoutReceiptTest.php
    └── ReconnectTest.php
```

PHP-пакет не содержит media engine и не заменяет LiveKit runtime.

## Filament

```text
extensions/filament/
├── composer.json
├── README.md
├── src/
│   ├── AgentoolsPanelPlugin.php
│   ├── Resources/
│   │   ├── AssistantResource.php
│   │   ├── ProfileVersionResource.php
│   │   ├── SkillVersionResource.php
│   │   ├── DeploymentResource.php
│   │   ├── RunResource.php
│   │   ├── ActionResource.php
│   │   └── UsageResource.php
│   ├── Pages/
│   │   ├── OperationsDashboard.php
│   │   ├── ProfileDiff.php
│   │   ├── RunInspector.php
│   │   └── RecoveryQueue.php
│   └── Actions/
│       ├── PublishProfileAction.php
│       ├── DisableDeploymentAction.php
│       └── RequestReconciliationAction.php
└── tests/
    ├── PanelAuthorizationTest.php
    ├── TenantScopeTest.php
    └── PublishedProfileImmutabilityTest.php
```

Админка вызывает application services. Она не выполняет `UPDATE status = 'succeeded'` напрямую.

## Evaluation

```text
extensions/evaluation/
├── composer.json
├── README.md
├── resources/
│   └── migrations/
│       └── 001_evaluation.sql
├── src/
│   ├── EvaluationServiceProvider.php
│   ├── EvalSuite.php
│   ├── EvalCase.php
│   ├── EvalRun.php
│   ├── EvalRunner.php
│   ├── Evaluator.php
│   ├── DeterministicEvaluator.php
│   ├── ModelJudgeEvaluator.php
│   ├── QualificationPolicy.php
│   ├── DeploymentQualification.php
│   └── PgEvaluationRepository.php
└── tests/
    ├── QualificationTest.php
    ├── EvaluatorIsolationTest.php
    └── EvaluationReproducibilityTest.php
```


## Scheduler

```text
extensions/scheduler/
├── composer.json
├── README.md
├── resources/
│   └── migrations/
│       └── 001_scheduler.sql
├── src/
│   ├── SchedulerServiceProvider.php
│   ├── AssistantSchedule.php
│   ├── ScheduledOccurrence.php
│   ├── DueScheduleScanner.php
│   ├── StartScheduledRun.php
│   └── PgScheduleRepository.php
└── tests/
    ├── OccurrenceDeduplicationTest.php
    ├── TimezoneTest.php
    └── ConcurrentScannerTest.php
```

Так сохраняется полезная идея cadence из Botkit, но она не становится обязательной частью каждого AI-запроса.


<!-- source-lines: 7138–7558 -->

## Модель базы данных

## Общие правила

Рекомендую общий префикс `agt_`.

Для tenant-scoped таблиц:

```text
tenant_id
id
```

Основные правила:

- Внутренние IDs — UUID, генерируемые приложением.
- Внешние IDs — непрозрачные ссылки, не обязательно UUID.
- Время — UTC, тип `timestamptz`.
- Токены — `bigint`, допускающий `NULL`, если значение неизвестно.
- Денежные оценки — точный decimal или целые minor/micro units с явной валютой и scale.
- Не использовать `float` для денег.
- JSONB — для типизированных manifests, а не вместо всей схемы.
- Секреты — только через `secret_ref`.
- Большие чувствительные payloads — через зашифрованное хранилище и `payload_ref`.
- Внешних FK на таблицы Edufy нет.
- Внутренние tenant-scoped связи защищаются составными ключами.


### Глобальные профили

Чтобы не смешивать platform-данные с tenant-данными:

- Базовые глобальные определения могут поставляться кодом или доверенным versioned registry.
- В tenant-БД хранятся назначение и скомпилированный bundle.
- Bundle содержит проверенные ссылки и hashes базовых версий.
- Нельзя просто прочитать профиль другого tenant как «родительский».

Публикация глобальных bundles из БД может появиться позже как отдельный control-plane, а не как случайный `tenant_id = NULL`.

## Профили и ассистенты

| Таблица | Основные поля | Ключевые ограничения |
| :-- | :-- | :-- |
| `agt_assistants` | `tenant_id`, `id`, `key`, `definition_key`, `name`, `status`, `default_profile_id`, `version` | UNIQUE `(tenant_id, key)` |
| `agt_profiles` | `tenant_id`, `id`, `key`, `name`, `latest_published_version_id` | UNIQUE `(tenant_id, key)` |
| `agt_profile_versions` | `tenant_id`, `id`, `profile_id`, `version_number`, `status`, `manifest`, `content_hash`, `created_by`, `published_at` | UNIQUE `(tenant_id, profile_id, version_number)` |
| `agt_skills` | `tenant_id`, `id`, `key`, `name` | UNIQUE `(tenant_id, key)` |
| `agt_skill_versions` | `tenant_id`, `id`, `skill_id`, `version_number`, `manifest`, `content_hash`, `status` | Неизменяемость опубликованной версии |
| `agt_profile_bundles` | `tenant_id`, `id`, `profile_version_id`, `bundle_hash`, `manifest`, `provenance`, `compiler_version` | UNIQUE `(tenant_id, bundle_hash)` |
| `agt_profile_assignments` | `tenant_id`, `id`, `assistant_id`, `selector_kind`, `selector_ref`, `profile_version_id`, `priority`, `valid_from`, `valid_until` | Однозначность назначения по принятой policy |

`selector_ref` — непрозрачная ссылка host-приложения. AgenTools не знает, что за ней находится: школа, отдел или workspace.

Не рекомендую произвольный JSON selector, который администратор превращает в SQL-фильтр. Допустимые виды selector регистрируются кодом.

## Провайдеры и маршрутизация

| Таблица | Основные поля | Назначение |
| :-- | :-- | :-- |
| `agt_provider_accounts` | `tenant_id`, `id`, `provider_key`, `secret_ref`, `project_ref`, `region`, `status` | Подключение к провайдеру |
| `agt_deployments` | `tenant_id`, `id`, `account_id`, `transport_key`, `model_ref`, `endpoint_ref`, `region`, `admin_status`, `health_status`, `version` | Конкретная исполняемая конфигурация |
| `agt_capability_snapshots` | `tenant_id`, `id`, `deployment_id`, `capabilities`, `source`, `verified_at`, `expires_at` | Проверенные возможности deployment |
| `agt_routing_policies` | `tenant_id`, `id`, `key`, `version_number`, `manifest`, `content_hash`, `status` | Ограничения и приоритеты выбора |
| `agt_operational_overrides` | `tenant_id`, `id`, `target_kind`, `target_ref`, `decision`, `reason`, `valid_until`, `created_by` | Аварийные запреты |
| `agt_price_versions` | `tenant_id`, `id`, `deployment_id`, `currency`, `rates`, `effective_from`, `source_ref` | Версия тарифа для оценки стоимости |
| `agt_provider_cache_entries` | `tenant_id`, `id`, `deployment_id`, `cache_key`, `provider_resource_ref`, `content_hash`, `security_scope_hash`, `expires_at`, `status` | Учёт explicit cache resources |

Не хранить ключи API в `manifest` или `provider_cache_entries`.

Общий платформенный аккаунт провайдера должен подключаться через явный credential broker/host adapter. Не нужно давать tenant произвольное чтение platform credentials.

## Сессии и исполнение

| Таблица | Основные поля | Ключевые ограничения |
| :-- | :-- | :-- |
| `agt_sessions` | `tenant_id`, `id`, `assistant_id`, `profile_bundle_id`, `actor_ref`, `conversation_ref`, `host_session_ref`, `status`, `working_state_ref`, `version` | Host session — ссылка, не копия объекта Edufy |
| `agt_turns` | `tenant_id`, `id`, `session_id`, `source_key`, `source_event_id`, `input_ref`, `modality`, `accepted_at` | UNIQUE `(tenant_id, source_key, source_event_id)` |
| `agt_runs` | `tenant_id`, `id`, `turn_id`, `status`, `checkpoint`, `version`, `lease_owner`, `lease_until`, `fencing_token`, `deadline_at`, `cancel_requested_at` | Не больше одного активного run на turn |
| `agt_run_steps` | `tenant_id`, `id`, `run_id`, `sequence`, `kind`, `status`, `input_ref`, `output_ref`, `operation_key` | UNIQUE `(tenant_id, run_id, sequence)` |
| `agt_model_invocations` | `tenant_id`, `id`, `run_id`, `step_id`, `deployment_id`, `context_snapshot_id`, `status`, `request_ref`, `response_ref`, `provider_request_id`, `started_at`, `finished_at` | Один invocation не переименовывается при fallback |
| `agt_context_snapshots` | `tenant_id`, `id`, `session_id`, `run_id`, `manifest`, `content_hash`, `rendered_payload_ref`, `compiler_version`, `token_report` | Снимок неизменяем |
| `agt_summary_versions` | `tenant_id`, `id`, `session_id`, `version_number`, `through_turn_id`, `summary_ref`, `source_manifest`, `status` | Источник и граница summary обязательны |
| `agt_delivery_receipts` | `tenant_id`, `id`, `run_id`, `destination_key`, `delivery_key`, `external_ref`, `status`, `delivered_payload_ref`, `delivered_at` | UNIQUE `(tenant_id, destination_key, delivery_key)` |

### Важное различие

`agt_run_steps` — checkpoints исполнения.

Это **не второй action journal**.

Run step может сказать:

```text
На шаге 7 создан action A.
Ожидается его терминальный результат.
```

Но не должен копировать всю историю попыток action A.

## Действия

| Таблица | Основные поля | Ключевые ограничения |
| :-- | :-- | :-- |
| `agt_actions` | `tenant_id`, `id`, `definition_key`, `definition_version`, `definition_hash`, `status`, `origin_ref`, `idempotency_key`, `input_ref`, `input_hash`, `outcome_ref`, `version`, `lease_until`, `fencing_token` | UNIQUE `(tenant_id, definition_key, idempotency_key)` |
| `agt_action_events` | `tenant_id`, `id`, `action_id`, `sequence`, `event_type`, `event_version`, `data`, `payload_ref`, `occurred_at` | UNIQUE `(tenant_id, action_id, sequence)` |
| `agt_action_attempts` | `tenant_id`, `id`, `action_id`, `attempt_number`, `worker_ref`, `fencing_token`, `status`, `started_at`, `finished_at`, `error_code` | UNIQUE `(tenant_id, action_id, attempt_number)` |
| `agt_action_effects` | `tenant_id`, `id`, `action_id`, `effect_key`, `effect_type`, `request_hash`, `request_ref`, `owner_operation_ref`, `status`, `result_ref`, `last_reconciled_at` | UNIQUE `(tenant_id, action_id, effect_key)` |
| `agt_action_confirmations` | `tenant_id`, `id`, `action_id`, `actor_ref`, `input_hash`, `scope_hash`, `policy_version`, `decision`, `expires_at`, `decided_at`, `proof_ref` | Подтверждение связано с точным входом и scope |

### Источник истины

Для actions рекомендую:

- Append-only event journal — каноническая история переходов.
- `agt_actions` — транзакционно обновляемая проекция текущего состояния.
- Обновление event + projection + outbox — в одной транзакции.

Для sessions/runs **не требуется внедрять полное event sourcing**. Там достаточно версионированного состояния и checkpoints.

Это осознанная асимметрия, а не нарушение единообразия.

## Использование и бюджеты

| Таблица | Основные поля | Назначение |
| :-- | :-- | :-- |
| `agt_usage_records` | `tenant_id`, `id`, `source_kind`, `source_id`, `deployment_id`, `usage_kind`, `quantity`, `unit`, `certainty`, `price_version_id`, `estimated_amount`, `currency` | Учёт разных видов ресурсов |
| `agt_budget_limits` | `tenant_id`, `id`, `scope_kind`, `scope_ref`, `period`, `resource_kind`, `limit_amount`, `currency`, `version` | Лимиты |
| `agt_budget_reservations` | `tenant_id`, `id`, `run_id`, `limit_id`, `reserved_amount`, `settled_amount`, `status`, `expires_at`, `version` | Admission control |

Здесь нет отдельного финансового ledger.

Если проект использует Billium/Commerce:

- Agentools сообщает usage.
- `BudgetPort` обращается к владельцу бюджета.
- Финансовые обязательства остаются у владельца денег.

Локальные таблицы лимитов пригодны для технических квот, но не должны становиться вторым кошельком.

## Интеграция и аудит

| Таблица | Основные поля | Назначение |
| :-- | :-- | :-- |
| `agt_outbox` | `tenant_id`, `id`, `destination`, `event_key`, `event_type`, `payload_ref`, `available_at`, `attempts`, `lease_until`, `published_at` | Надёжная доставка |
| `agt_inbox` | `tenant_id`, `id`, `source`, `message_id`, `payload_hash`, `status`, `received_at`, `processed_at` | Дедупликация входа |
| `agt_audit_events` | `tenant_id`, `id`, `actor_ref`, `operation`, `resource_ref`, `decision`, `reason_code`, `trace_id`, `occurred_at` | Управленческий и security audit |
| `agt_artifacts` | `tenant_id`, `id`, `storage_ref`, `content_hash`, `media_type`, `classification`, `encryption_key_ref`, `retention_policy`, `expires_at`, `deleted_at` | Каталог защищённых payloads |

Самих больших bytes в `agt_artifacts` нет.

### Inbox-конфликт

Если пришёл тот же:

```text
source + message_id
```

но с другим `payload_hash`, это не обычный дубль. Нужна ошибка целостности или отдельное расследование.

## Knowledge-таблицы

Устанавливаются только с расширением.


| Таблица | Основные поля |
| :-- | :-- |
| `agt_knowledge_materials` | Каталожная запись, `owner_ref`, `material_type`, `access_policy_ref` |
| `agt_knowledge_material_versions` | `material_id`, `version_number`, `source_file_ref`, `source_hash`, лицензия и ограничения |
| `agt_knowledge_processing_runs` | Pipeline version, модельные/инструментальные ссылки, статус |
| `agt_knowledge_nodes` | Структура документа: parent, тип узла, страницы, payload |
| `agt_knowledge_fragments` | Исходные фрагменты, координаты, hashes, visibility class |
| `agt_knowledge_cards` | Производные карточки, schema version, source refs, validation status |
| `agt_knowledge_validations` | Проверка, evaluator, результат, evidence |
| `agt_knowledge_corpora` | Логическая коллекция |
| `agt_knowledge_releases` | Неизменяемая опубликованная версия корпуса |
| `agt_knowledge_release_items` | Связь release с конкретными material versions |
| `agt_knowledge_index_entries` | Fragment, embedding model/version, индексный статус |
| `agt_knowledge_dependencies` | Зависимости производных артефактов для инвалидирования |

Права на материалы может предоставлять внешний каталог. Тогда `agt_knowledge_materials` — техническое представление с `owner_ref`, а не конкурирующий владелец лицензий.

### Embeddings

Нельзя смешивать embeddings разных моделей в одном пространстве без явного разделения.

Хранить:

```text
embedding_model
embedding_revision
dimensions
normalization
index_version
```

Конкретная vector-структура зависит от выбранного backend. Не нужно делать обязательным один размер в общей доменной модели.

## Evaluation и Scheduler

Дополнительные таблицы:

```text
agt_eval_suites
agt_eval_cases
agt_eval_runs
agt_eval_results
agt_deployment_qualifications

agt_schedules
agt_schedule_occurrences
```

У schedule occurrence должен быть стабильный уникальный ключ:

```text
schedule_id + planned_occurrence_at
```

Именно occurrence, а не каждый retry scanner.

## Критические SQL-ограничения

Пример принципа защиты tenant-scoped связей:

```sql
CREATE TABLE agt_actions (
    tenant_id text NOT NULL,
    id uuid NOT NULL,
    definition_key text NOT NULL,
    definition_version text NOT NULL,
    idempotency_key text NOT NULL,
    input_hash text NOT NULL,
    status text NOT NULL,
    version bigint NOT NULL DEFAULT 0,

    PRIMARY KEY (tenant_id, id),

    UNIQUE (
        tenant_id,
        definition_key,
        idempotency_key
    )
);

CREATE TABLE agt_action_events (
    tenant_id text NOT NULL,
    id uuid NOT NULL,
    action_id uuid NOT NULL,
    sequence bigint NOT NULL,
    event_type text NOT NULL,
    event_version integer NOT NULL,
    data jsonb NOT NULL,
    occurred_at timestamptz NOT NULL,

    PRIMARY KEY (tenant_id, id),

    UNIQUE (
        tenant_id,
        action_id,
        sequence
    ),

    FOREIGN KEY (tenant_id, action_id)
        REFERENCES agt_actions (tenant_id, id)
);
```

Это фрагмент схемы, не полный production DDL.

### Зачем составной FK

Он не позволяет связать event tenant A с action tenant B даже при ошибке application code.

Однако **составной FK не заменяет авторизацию чтения**.

### RLS

PostgreSQL RLS можно добавить как второй уровень защиты, но только после проектирования:

- Как устанавливается tenant context.
- Как он сбрасывается в connection pool.
- Как работают background jobs.
- Какие роли могут обходить RLS.
- Как выполняются migrations и recovery.

Нельзя включить RLS и считать задачу tenant isolation закрытой.

## Публичные контракты

## ExecutionContext

Контекст исполнения создаётся доверенным host-кодом, а не принимается целиком из браузера.

```php
<?php

namespace Axioma\Agentools\Common;

final readonly class ExecutionContext
{
    public function __construct(
        public TenantId $tenant,
        public ActorRef $actor,
        public string $actingAs,
        public string $purpose,
        public string $authorizationRef,
        public string $policyRevision,
        public Correlation $correlation,
    ) {}
}
```

`authorizationRef` не является вечным разрешением. Это ссылка на контекст проверки; доменный владелец всё равно проверяет актуальные полномочия перед действием.

Не стоит помещать сюда бесконтрольный:

```php
public array $permissions;
```

который все компоненты считают истиной.

## ModelTransport

```php
<?php

namespace Axioma\Agentools\Models;

interface ModelTransport
{
    public function generate(
        ModelRequest $request,
        ModelDeployment $deployment,
    ): ModelResponse;

    public function stream(
        ModelRequest $request,
        ModelDeployment $deployment,
    ): ModelEventStream;
}
```

`ModelRequest` содержит типизированные:

- Сообщения.
- Части контента.
- Tool declarations.
- Требования к структуре ответа.
- Output budget.
- Cache intent.
- Ссылку на context snapshot.
- Идентификатор invocation.
- Cancellation/deadline constraints.

Не один `string $prompt` и произвольный `array $options`.

## ActionExecutor

```php
<?php

namespace Axioma\Agentools\Actions;

interface ActionExecutor
{
    public function submit(ActionRequest $request): Action;

    public function get(
        string $actionId,
        \Axioma\Agentools\Common\ExecutionContext $context,
    ): Action;

    public function requestCancellation(
        string $actionId,
        \Axioma\Agentools\Common\ExecutionContext $context,
    ): Action;
}
```

В реальной реализации IDs желательно оформить value objects. Здесь строки оставлены для читаемости примера.

### Важная семантика

`submit()` означает:

> Операция принята и имеет устойчивую идентичность.

Не обязательно:

> Операция уже завершена.

Синхронное быстрое исполнение может быть оптимизацией, но не должно менять контракт надёжности.

## ActionHandler

```php
<?php

namespace Axioma\Agentools\Actions;

interface ActionHandler
{
    public function execute(
        ActionExecution $execution,
    ): ActionOutcome;
}
```

Обработчик получает:

- Валидированный input.
- Execution context.
- Стабильный action ID.
- API выполнения и учёта эффектов.
- Deadline.
- Cancellation state.

Он не должен получать весь разговор или Laravel container.


<!-- source-lines: 7559–7761 -->

## Пример инструмента Edufy

```php
<?php

namespace Edufy\AiCore\Infrastructure\Agentools;

use Axioma\Agentools\Actions\ActionExecution;
use Axioma\Agentools\Actions\ActionHandler;
use Axioma\Agentools\Actions\ActionOutcome;
use Edufy\Library\Contracts\LessonMaterials;

final class GetLessonMaterialHandler implements ActionHandler
{
    public function __construct(
        private LessonMaterials $materials,
    ) {}

    public function execute(
        ActionExecution $execution,
    ): ActionOutcome {
        $input = $execution->validatedInput();

        $result = $this->materials->findForLesson(
            actor: $execution->context()->actor,
            tenant: $execution->context()->tenant,
            lessonRef: $input->string('lesson_ref'),
            purpose: $execution->context()->purpose,
        );

        return ActionOutcome::succeeded(
            modelView: [
                'title' => $result->title,
                'fragments' => $result->approvedFragments,
                'source_refs' => $result->sourceReferences,
            ],
            resultRef: $result->reference,
        );
    }
}
```

Это пример целевого integration API. Сигнатуры `LessonMaterials` и вспомогательных классов должны быть согласованы с реальным Edufy.

Принцип здесь важнее конкретного синтаксиса:

- Обработчик не читает чужую таблицу.
- Владелец материалов проверяет доступ.
- Модель получает компактное представление.
- Полный результат остаётся у владельца.


## Канонический каталог инструментов

## Одно определение

Предлагаемая декларация:

```yaml
id: edufy.library.get-lesson-material
version: "1"

description: Получить разрешённые материалы конкретного урока.

input_schema:
  type: object
  additionalProperties: false
  required:
    - lesson_ref
  properties:
    lesson_ref:
      type: string

output_schema:
  type: object
  required:
    - title
    - fragments
    - source_refs

execution:
  mode: read
  timeout_seconds: 10
  retry_policy: bounded-read
  confirmation: never

security:
  purpose:
    - student_tutoring
  authorization: host
  result_classification: education_content
```


### Чего здесь нет

- Произвольного PHP class name из БД.
- Access token.
- Списка доверенных пользователей.
- SQL.
- Промпта, который сам себя объявляет разрешённым.

Связь `definition → handler` регистрируется кодом.

## Три представления

Из одного контракта строятся:

1. **Execution view:** всё необходимое action runtime.
2. **Model view:** название, описание и input schema для LLM.
3. **Admin view:** эксплуатационная информация.

Нельзя отдельно редактировать одну и ту же input schema в трёх местах.

### Не все инструменты показывать модели

Конкретный run получает пересечение:

```text
Зарегистрированные tools
∩ Profile allowlist
∩ Skill allowlist
∩ Host permissions
∩ Session restrictions
∩ Operational availability
```

Отсутствующий инструмент не должен появляться в model request.

## Профили и skills

## Что взять из Botkit

Из предыдущей концепции Botkit стоит сохранить:

- Регистрацию типов кодом.
- Конфигурацию экземпляров в БД.
- Managed entry point.
- Плагины.
- Тестовые подмены.
- Наблюдаемую pipeline.
- Headless-исполнение.
- Расписание как расширение.
- Административный интерфейс как отдельную оболочку.

Но переработать:


| Подход | Целевая версия |
| :-- | :-- |
| Редактирование активного bot config | Draft → validation → publish version |
| Большой набор options | Типизированные настройки и отдельные constraints |
| Raw agent как альтернативный путь | Явно ограниченный низкоуровневый API |
| Произвольные tools SDK | Проекция канонического ActionDefinition |
| Доменные bot tables | Либо технические артефакты, либо данные host |
| Монолитный plugin hook | Обязательные guards отдельно от optional extensions |

Это цели переноса, а не утверждение, что текущая реализация уже нарушает эти правила.

## Profile manifest

```yaml
key: teacher-physics
version: 12

definition: educational-assistant

layers:
  - platform:assistant-safety@7
  - edufy:teacher-core@8
  - edufy:inquiry-method@3
  - tenant:school-teaching-policy@4

skills:
  - edufy:explain-concept@5
  - edufy:guided-practice@6

context:
  providers:
    - learner-slice
    - current-lesson
    - lesson-evidence
    - conversation-history

tools:
  allow:
    - edufy.library.get-lesson-material@1
    - edufy.learning.get-task@1

models:
  policy: ru-education-balanced@3

limits:
  max_model_invocations: 6
  max_tool_calls: 8
  max_parallel_read_tools: 2

output:
  schema: tutor-response@2
```

Числа здесь — **пример конфигурации**, не универсальные рекомендуемые лимиты.
