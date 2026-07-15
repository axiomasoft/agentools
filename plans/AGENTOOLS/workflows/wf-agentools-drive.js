export const meta = {
  name: 'agentools-drive',
  description: 'Довести plans/AGENTOOLS до milestone Dart SDK (P0 спека → P1 conformance → P2 PHP SDK → P3 Dart SDK) с research, адверсариальным ревью и close-механикой по plan-protocol',
  whenToUse: 'ECOSYSTEM P4.1: полный прогон делегата AGENTOOLS до milestone, гейтящего AiResto. Запуск — только по «Запускай» владельца. Resume: Workflow({scriptPath, resumeFromRunId})',
  phases: [
    { title: 'Scout', detail: 'фактические статусы items из phases/*.md' },
    { title: 'Research', detail: 'RAG-довесы перед спекой (MCP-ревизия 2026-07-28) — Sonnet' },
    { title: 'Exec', detail: 'исполнение item по ТЗ: спека — Opus/high, код — Sonnet/medium', model: 'opus' },
    { title: 'Review', detail: 'ревью артефакта по линзам + адверсариальная верификация Major+' },
    { title: 'Fix', detail: 'фиксы подтверждённых находок отдельным коммитом' },
    { title: 'Close', detail: 'статусы/Update Log/handoff/plan-lint/vault-коммит — Sonnet/low' },
    { title: 'Audit', detail: 'GREEN-аудит фазы — Opus/xhigh; RED → fix-раунд → re-audit', model: 'opus' },
    { title: 'Final', detail: 'semver-тег + закрытие ECOSYSTEM P4.1' },
  ],
}

// ── Константы ──────────────────────────────────────────────────────────────
const VAULT = '/home/vostrikov/Vaults/Brain'
const PLAN = `${VAULT}/plans/AGENTOOLS`
const ECO = `${VAULT}/plans/ECOSYSTEM`
const REPO = '/home/vostrikov/projects/packages/agentools'
const LINT = 'python3 /home/vostrikov/projects/packages/swissknifeman/packages/task/scripts/plan-lint.py'

// Роутинг — зеркало plan.md §3 Routing (SSOT — там; здесь детерминизм скрипта).
const ROUTING = {
  spec: { model: 'opus', effort: 'high' },      // P0.3–P0.8, P0.10 — нормативный авторинг
  fixtures: { model: 'sonnet', effort: 'medium' }, // P0.9 — контрактные векторы
  code: { model: 'sonnet', effort: 'medium' },  // P1–P3 — дефолт матрицы
  mech: { model: 'sonnet', effort: 'low' },     // P0.11 и прочий vault-синк
}
function kindOf(id) {
  if (/^P0\.(3|4|5|6|7|8|10)$/.test(id)) return 'spec'
  if (id === 'P0.9') return 'fixtures'
  if (id === 'P0.11') return 'mech'
  return 'code'
}
// RAG-довесы перед exec (open risk из handoff: MCP RC 2026-07-28)
const RESEARCH_BY_ITEM = {
  'P0.3': 'MCP ревизия 2025-11-25 и её RC 2026-07-28: изменились ли контракты tools (name/annotations/_meta/structured output) относительно 2025-11-25? Только факты с URL и датой; итог: «изменений нет» или список изменений. Ответ для AI-агента: плотные буллеты, без advice.',
  'P0.5': 'MCP elicitation (введена 2025-06-18, url-mode 2025-11-25) и RC 2026-07-28: изменения полей form-mode/url-mode? Появилась ли поддержка elicitation в официальном PHP SDK mcp/sdk? Факты с URL и датой; неизвестно — писать unknown.',
}

// ── Схемы structured output ────────────────────────────────────────────────
const ITEMS_SCHEMA = {
  type: 'object', required: ['items'], additionalProperties: false,
  properties: {
    items: {
      type: 'array', items: {
        type: 'object', required: ['id', 'title', 'status'], additionalProperties: false,
        properties: { id: { type: 'string' }, title: { type: 'string' }, status: { type: 'string' } },
      },
    },
  },
}
const EXEC_SCHEMA = {
  type: 'object', required: ['status', 'summary', 'files', 'validation', 'escalation', 'pending'],
  additionalProperties: false,
  properties: {
    status: { enum: ['done', 'blocked'] },
    summary: { type: 'string', description: 'Факты для Completion Notes, 3–8 строк' },
    files: { type: 'array', items: { type: 'string' } },
    commits: { type: 'array', items: { type: 'string' } },
    validation: {
      type: 'array', items: {
        type: 'object', required: ['cmd', 'result'], additionalProperties: false,
        properties: { cmd: { type: 'string' }, result: { type: 'string' } },
      },
    },
    escalation: {
      anyOf: [{ type: 'null' }, {
        type: 'object', required: ['reason', 'trigger'], additionalProperties: false,
        properties: {
          reason: { type: 'string' },
          trigger: { enum: ['многопакетный рефакторинг', 'неясная архитектура', 'public contract/SemVer', 'protected generated assets', 'конфликт правил', 'неясные acceptance'] },
        },
      }],
    },
    pending: { type: 'array', items: { type: 'string' } },
  },
}
const FINDINGS_SCHEMA = {
  type: 'object', required: ['findings'], additionalProperties: false,
  properties: {
    findings: {
      type: 'array', items: {
        type: 'object', required: ['severity', 'where', 'summary', 'fix_hint'], additionalProperties: false,
        properties: {
          severity: { enum: ['Blocker', 'Major', 'Minor', 'Nit'] },
          where: { type: 'string' }, summary: { type: 'string' }, fix_hint: { type: 'string' },
        },
      },
    },
  },
}
const VERDICT_SCHEMA = {
  type: 'object', required: ['isReal', 'note'], additionalProperties: false,
  properties: { isReal: { type: 'boolean' }, note: { type: 'string' } },
}
const CLOSE_SCHEMA = {
  type: 'object', required: ['status', 'lint', 'commit', 'notes'], additionalProperties: false,
  properties: {
    status: { enum: ['ok', 'fail'] }, lint: { enum: ['ok', 'fail'] },
    commit: { type: 'string' }, notes: { type: 'string' },
  },
}
const AUDIT_SCHEMA = {
  type: 'object', required: ['verdict', 'findings'], additionalProperties: false,
  properties: {
    verdict: { enum: ['GREEN', 'RED'] },
    findings: { type: 'array', items: { type: 'string' } },
  },
}

// ── Общий преамбул агентов ─────────────────────────────────────────────────
const COMMON = `Дом кода: ${REPO} (git-репо пакета AgenTools). Дом плана: ${PLAN} (vault Brain, git-корень ${VAULT}).
Жёсткие правила plan-protocol:
- Исполнять СТРОГО Scope Included item'а; «улучшить заодно» — только строкой в pending, НЕ делать.
- План разошёлся с реальностью (файла нет, якорь не находится) → НЕ импровизировать: вернуть escalation.
- Внешние факты — только RAG с URL (perplexity-web через ToolSearch); без источника — [UNVERIFIED].
- Спека: русский текст, нормативные слова RFC 2119 англ. (MUST/SHOULD/MAY), идентификаторы/поля англ.
- Код, комментарии, описания тестов — на русском, объяснять «почему» (general:writing-style).
- Решения D1–D15 из ${PLAN}/plan.md §5 НЕ пересматривать.`

// ── Промпты ────────────────────────────────────────────────────────────────
const openPrompt = (ph, it) => `Механическая правка статуса (открытие item ${it.id} плана AGENTOOLS).
В файле ${PLAN}/phases/${ph}.md: у item ${it.id} поле «**Status:**» и его строку в таблице «## Phase Status» поменяй на «🟡 In progress» (байт-в-байт, эмодзи+текст как в других строках), дату Updated в таблице — на сегодняшнюю (возьми из \`date +%F\`). Больше НИЧЕГО не менять, git-коммит НЕ делать. Ответ: «ok».`

const execPrompt = (ph, it, research) => `${COMMON}

Ты — исполнитель item ${it.id} («${it.title}») плана AGENTOOLS.
Порядок чтения (ровно такой): 1) ${PLAN}/plan.md — §2 Execution Rules, §3 Routing, §5 Decision Log; 2) ${PLAN}/phases/${ph}.md — Phase Context + item ${it.id} ЦЕЛИКОМ; 3) файлы из Required Reads item'а в указанном порядке. Сверх этого не читать.
${research ? `RAG-контекст, проверенный перед запуском (используй, не перепроверяй):\n${research}\n` : ''}
Выполни работу item'а по его ТЗ (Scope Included / Implementation Rules / Code Guidance — жёсткие рамки). Затем прогони КАЖДУЮ команду из поля Validation и зафиксируй результат.
Диффы по Scope в ${REPO} — закоммить (Conventional Commits, рус., формат «тип(scope): суть (${it.id})», атомарно по смыслу). Если deliverable item'а — vault-файл (напр. ${PLAN}/20_research/*.md) — создай/правь его, но vault НЕ коммить и статусы/handoff НЕ трогай (закроет отдельный агент).
Ответ строго по схеме: status done|blocked · summary (факты для Completion Notes) · files · commits (hash — subject) · validation (cmd → passed/failed + суть) · escalation (null | {reason, trigger}) · pending.`

const reviewPrompt = (ph, it, lens) => `${COMMON}

Ты — адверсариальный ревьюер item ${it.id} плана AGENTOOLS, линза: **${lens}**.
Прочитай ТЗ item'а (${PLAN}/phases/${ph}.md, item ${it.id}) и созданный им артефакт (git diff/log в ${REPO} по коммитам «(${it.id})» + файлы из поля Files item'а). Read-only: ничего не менять.
Ищи ТОЛЬКО реальные дефекты по своей линзе; не изобретай стилистику. Severity: Blocker (ломает контракт/валидацию) / Major (расхождение с ТЗ или спекой) / Minor / Nit.
Ответ по схеме findings; пустой список — легитимный результат.`

const verifyPrompt = (f) => `Адверсариально проверь находку ревью (попробуй ОПРОВЕРГНУТЬ): «[${f.severity}] ${f.where}: ${f.summary}». Прочитай указанное место в ${REPO} / ${PLAN} и реши: находка реальна или ложная тревога? При неуверенности — isReal:false. Ответ по схеме.`

const fixPrompt = (ph, it, findings) => `${COMMON}

Ты — фиксер item ${it.id} плана AGENTOOLS. Исправь ТОЛЬКО перечисленные подтверждённые находки ревью (ничего сверх):
${findings.map((f, i) => `${i + 1}. [${f.severity}] ${f.where}: ${f.summary} — hint: ${f.fix_hint}`).join('\n')}
Контекст: ТЗ item'а — ${PLAN}/phases/${ph}.md. Прогони Validation item'а заново. Фиксы — ОТДЕЛЬНЫМ коммитом в ${REPO} («fix(scope): фиксы по ревью (${it.id})»), НЕ amend.
Ответ по той же схеме exec (status/summary/files/commits/validation/escalation/pending).`

const closePrompt = (ph, it, ex, pendingExtra) => `${COMMON}

Ты — close-агент item ${it.id} плана AGENTOOLS (только механика, НЕ дизайн).
Данные исполнителя:
- summary: ${ex.summary}
- files: ${ex.files.join(', ') || '—'}
- commits: ${(ex.commits || []).join('; ') || '—'}
- validation: ${ex.validation.map(v => `${v.cmd} → ${v.result}`).join(' · ')}
- pending: ${[...(ex.pending || []), ...(pendingExtra || [])].join('; ') || '—'}
Сделай в vault (${PLAN}):
1) phases/${ph}.md, item ${it.id}: Status → «🟢 Done» (канонично байт-в-байт) И строка в «## Phase Status»; Completion Notes ← факты из данных выше (без оценок); Pending Work ← pending (или «—»); Known Deviations — только если validation имеет failed или модель/effort разошлись с §3 Routing.
2) plan.md: счётчик фазы в «## 4. Phase Index & Status Board»; строка в «## 6. Update Log» — ≤2 строк, формат «${it.id} закрыт: <суть 1 строка> — детали см. phases/${ph}.md ${it.id} Completion Notes», ≤300 символов.
3) Перезапиши ${PLAN}/handoff.md целиком по схеме протокола (# HANDOFF — <ISO> — after ${it.id}; Next c launch-block таблицей Model/Thinking/Context/Суть + голая команда «/task:plan-exec AGENTOOLS <следующий item>» отдельным code-block; Done/Remaining/Sources of truth/Open risks/Workarounds-Deferred-Open questions).
4) Прогони «${LINT} plans/AGENTOOLS» (cwd ${VAULT}) — красное почини (в рамках механики).
5) Закоммить vault-дифф этого item'а: «docs(plan): AGENTOOLS ${it.id} закрыт — <суть> (P4.1)» (только файлы плана AGENTOOLS + артефакты item'а в vault).
Ответ по схеме: status/lint/commit(hash — subject)/notes.`

const auditPrompt = (ph) => `${COMMON}

Ты — аудитор фазы ${ph} плана AGENTOOLS (GREEN-аудит закрытой фазы, adversarial).
Прочитай ${PLAN}/plan.md, ${PLAN}/phases/${ph}.md и сверь КАЖДЫЙ item 🟢 с реальностью: артефакты существуют (${REPO} и vault), git-коммиты соответствуют Completion Notes, Validation-команды реально проходят (перепрогони ключевые: spec_lint / тесты SDK — по фазе), no-op и scope drift отсутствуют, статусы/счётчики консистентны.
Вердикт GREEN только если всё сходится; иначе RED + конкретные находки (файл/что не так). Read-only.
Ответ по схеме verdict/findings.`

// ── Ревью-линзы по типу item ───────────────────────────────────────────────
function lensesFor(kind) {
  if (kind === 'spec') return [
    { lens: 'соответствие ТЗ: каждый пункт Scope Included представлен, ничего вне Scope, правила пронумерованы, примеры валидны против схем', model: 'opus', effort: 'high' },
    { lens: 'внутренняя консистентность спеки: термины/кросс-ссылки на другие доки ревизии, enum-ы не дублируются, схемы strict (additionalProperties:false, description ru)', model: 'opus', effort: 'medium' },
  ]
  if (kind === 'fixtures') return [
    { lens: 'контрактная строгость: каждый вектор валиден против fixture.json, spec_refs существуют, покрытие доков 01–06, golden-файлы воспроизводимы', model: 'opus', effort: 'high' },
  ]
  if (kind === 'code') return [
    { lens: 'корректность: баги, крайние случаи, ошибки в логике, тесты реально проверяют поведение', model: 'sonnet', effort: 'high' },
    { lens: 'соответствие спеке: реализация не расходится с нормативными правилами spec/revisions (TCP/LC/CP/SC/AU/IE) и fixtures', model: 'sonnet', effort: 'medium' },
  ]
  return [] // mech — без ревью, ловит аудит фазы
}

// ── Основной цикл ──────────────────────────────────────────────────────────
// args: {phases?: ['P0',...], designOnly?: true} — дизайн-волна зовёт с {phases:['P0'], designOnly:true}
const ARGS = (typeof args === 'string') ? JSON.parse(args) : (args || {})
const PHASES = ARGS.phases || ['P0', 'P1', 'P2', 'P3']
const report = { items: [], audits: [], stopped: null }

for (const ph of PHASES) {
  if (budget.total && budget.remaining() < 100000) { report.stopped = `бюджет: осталось ${Math.round(budget.remaining() / 1000)}k`; break }

  phase('Scout')
  const scout = await agent(
    `Прочитай ${PLAN}/phases/${ph}.md и верни ВСЕ items фазы: id (Pn.m), title, status (байт-в-байт из поля **Status:**). Только чтение.`,
    { label: `scout:${ph}`, model: 'sonnet', effort: 'low', schema: ITEMS_SCHEMA, phase: 'Scout' })
  if (!scout) { report.stopped = `scout ${ph} умер`; break }
  const todo = scout.items.filter(i => i.status.includes('Not started') || i.status.includes('In progress'))
  log(`${ph}: к исполнению ${todo.length}/${scout.items.length} items`)

  for (const it of todo) {
    const kind = kindOf(it.id)
    const route = ROUTING[kind]

    // Research-довес (если предписан)
    let research = ''
    if (RESEARCH_BY_ITEM[it.id]) {
      const r = await agent(
        `RAG-research (perplexity-web через ToolSearch; query-craft: версия+контекст+timeframe). Вопрос: ${RESEARCH_BY_ITEM[it.id]}`,
        { label: `research:${it.id}`, model: 'sonnet', effort: 'medium', phase: 'Research' })
      research = r || ''
    }

    // Открытие статуса → exec
    await agent(openPrompt(ph, it), { label: `open:${it.id}`, model: 'haiku', effort: 'low', phase: 'Close' })
    let ex = await agent(execPrompt(ph, it, research),
      { label: `exec:${it.id}`, model: route.model, effort: route.effort, schema: EXEC_SCHEMA, phase: 'Exec' })
    if (!ex) { report.stopped = `exec ${it.id} умер`; break }
    if (ex.status === 'blocked' || ex.escalation) {
      await agent(`В ${PLAN}/phases/${ph}.md item ${it.id}: Status → «🔴 Blocked» (и в Phase Status), добавь blockquote «> 🔴 ESCALATION ${it.id}: ${ex.escalation?.reason || ex.summary} | trigger: ${ex.escalation?.trigger || 'неясные acceptance'} | нужен: plan-design», Escalation Needed: yes. Обнови ${PLAN}/handoff.md (Next: /task:plan-design AGENTOOLS ${ph}). Vault-коммит «docs(plan): AGENTOOLS ${it.id} заблокирован (P4.1)».`,
        { label: `block:${it.id}`, model: 'sonnet', effort: 'low', phase: 'Close' })
      report.stopped = `ESCALATION-REQUIRED: ${it.id} — ${ex.escalation?.reason || ex.summary}`
      break
    }

    // Ревью → верификация → фикс (≤2 раундов)
    const pendingExtra = []
    let unresolvedBlocker = false
    for (let round = 1; round <= 2; round++) {
      const lenses = lensesFor(kind)
      if (!lenses.length) break
      const found = (await parallel(lenses.map(l => () =>
        agent(reviewPrompt(ph, it, l.lens), { label: `review:${it.id}:r${round}`, model: l.model, effort: l.effort, schema: FINDINGS_SCHEMA, phase: 'Review' })
      ))).filter(Boolean).flatMap(r => r.findings)
      if (!found.length) break

      const serious = found.filter(f => f.severity === 'Blocker' || f.severity === 'Major')
      const minor = found.filter(f => f.severity === 'Minor' || f.severity === 'Nit')
      pendingExtra.push(...minor.map(f => `ревью r${round} [${f.severity}] ${f.where}: ${f.summary}`))
      if (!serious.length) break

      const confirmed = (await parallel(serious.map(f => () =>
        agent(verifyPrompt(f), { label: `verify:${it.id}`, model: kind === 'spec' ? 'opus' : 'sonnet', effort: 'high', schema: VERDICT_SCHEMA, phase: 'Review' })
          .then(v => ({ f, v }))
      ))).filter(Boolean).filter(x => x.v.isReal).map(x => x.f)
      log(`${it.id} ревью r${round}: ${found.length} находок, подтверждено серьёзных ${confirmed.length}`)
      if (!confirmed.length) break

      if (round === 2) { unresolvedBlocker = confirmed.some(f => f.severity === 'Blocker'); break }
      const fixed = await agent(fixPrompt(ph, it, confirmed),
        { label: `fix:${it.id}`, model: route.model, effort: route.effort, schema: EXEC_SCHEMA, phase: 'Fix' })
      if (fixed) {
        ex = { ...ex, summary: `${ex.summary}\nРевью-фиксы: ${fixed.summary}`, files: [...new Set([...ex.files, ...fixed.files])], commits: [...(ex.commits || []), ...(fixed.commits || [])], validation: fixed.validation, pending: [...(ex.pending || []), ...(fixed.pending || [])] }
      }
    }
    if (unresolvedBlocker) { report.stopped = `${it.id}: Blocker не закрыт за 2 раунда ревью — нужен владелец`; break }

    // Закрытие item
    const cl = await agent(closePrompt(ph, it, ex, pendingExtra),
      { label: `close:${it.id}`, model: 'sonnet', effort: 'low', schema: CLOSE_SCHEMA, phase: 'Close' })
    report.items.push({ id: it.id, close: cl })
    if (!cl || cl.status === 'fail') { report.stopped = `${it.id}: close-механика упала (${cl?.notes || 'нет ответа'})`; break }
  }
  if (report.stopped) break

  // GREEN-аудит фазы (RED → один fix-раунд → re-audit)
  phase('Audit')
  let audit = await agent(auditPrompt(ph), { label: `audit:${ph}`, model: 'opus', effort: 'xhigh', schema: AUDIT_SCHEMA, phase: 'Audit' })
  if (audit && audit.verdict === 'RED') {
    log(`${ph} аудит RED: ${audit.findings.length} находок — fix-раунд`)
    await agent(`${COMMON}\nИсправь находки GREEN-аудита фазы ${ph} (только их): \n${audit.findings.map((f, i) => `${i + 1}. ${f}`).join('\n')}\nФиксы кода — коммит в ${REPO}; фиксы плана — vault-коммит «docs(plan): AGENTOOLS ${ph} фиксы аудита (P4.1)». Прогони Validation затронутых items.`,
      { label: `audit-fix:${ph}`, model: 'opus', effort: 'high', phase: 'Fix' })
    audit = await agent(auditPrompt(ph), { label: `re-audit:${ph}`, model: 'opus', effort: 'xhigh', schema: AUDIT_SCHEMA, phase: 'Audit' })
  }
  report.audits.push({ phase: ph, verdict: audit ? audit.verdict : 'нет ответа', findings: audit ? audit.findings : [] })
  if (!audit || audit.verdict !== 'GREEN') { report.stopped = `${ph}: аудит не GREEN после fix-раунда — нужен владелец`; break }
  log(`${ph} GREEN ✅`)
}

// ── Финал: semver-тег + ECOSYSTEM P4.1 (только полный прогон, НЕ designOnly) ─
if (!report.stopped && !ARGS.designOnly && report.audits.length === PHASES.length && report.audits.every(a => a.verdict === 'GREEN')) {
  phase('Final')
  const fin = await agent(`${COMMON}
Milestone достигнут: фазы P0–P3 AGENTOOLS закрыты с GREEN-аудитами. Сделай close-механику:
1) В ${REPO}: semver-тег v0.1.0 (git tag -a v0.1.0 -m "AgenTools 0.1.0: спека 2026-07 + conformance + PHP SDK + Dart SDK").
2) В ${ECO}/phases/P4.md item P4.1: Status → «🟢 Done» (и в Phase Status), Completion Notes — факты (фазы P0–P3 GREEN, тег v0.1.0, детали в plans/AGENTOOLS); Validation item'а выполнена (milestone Dart SDK P3 + GREEN-аудит + тег).
3) ${ECO}/plan.md: Status Board P4 счётчик +1, строка Update Log (≤300 симв., ссылка на Completion Notes).
4) Перезапиши ${ECO}/handoff.md (after P4.1; Next: P4.2 ControLLM — launch-block: opus не ниже, по D17 дизайн Fable/high → команда «/task:plan-exec ECOSYSTEM P4.2», Model fable, Thinking high).
5) «${LINT} plans/ECOSYSTEM» и «${LINT} plans/AGENTOOLS» — красное почини.
6) Vault-коммит «docs(plan): ECOSYSTEM P4.1 закрыт — AGENTOOLS milestone Dart SDK GREEN (P4.1)».
Ответ по схеме status/lint/commit/notes.`,
    { label: 'final:P4.1', model: 'sonnet', effort: 'low', schema: CLOSE_SCHEMA, phase: 'Final' })
  report.final = fin
}

return report
