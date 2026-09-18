// Оркестрация волны авторинга фазы P4 (A3) плана AGENTOOLS.
//
// Критерий plan-protocol §7 сработал на четырёх items с независимым scope и детерминированной
// Validation (P4.2, P4.3, P4.7, P4.8) — обоснование построчно: research/03-p4-vector-corpus-2026-08.md §9,
// решение — plan.md §5 D55, уточнённое D139.
//
// ПРЕДУСЛОВИЯ (скрипт проверяет их сам и останавливается, если не выполнены):
//   • фаза P3 закрыта, вердикт `ГЕЙТ A2: PASS`, существует spec/revisions/2026-08/rules.json;
//   • item P4.1 закрыт (формат вектора заморожен) — на нём стоят все четыре автора;
//   • items P4.6 и P4.4 закрыты: P4.4 solo обновляет общий вход `02`/`rules.json` по D139.
// Скрипт НЕ исполняет P4.1, P4.4, P4.5 и P4.6: они solo (см. roadmap/Routing §3).
//
// Запуск (новая сессия): Workflow({scriptPath: "05-Projects/03-Packages/AgenTools/plans/AGENTOOLS/workflows/wf-agentools-p4-vectors.js"})
// Резюм: Workflow({scriptPath: <тот же>, resumeFromRunId: "wf_…"})
export const meta = {
  name: 'agentools-p4-vectors',
  description: 'Волна авторинга тест-векторов ревизии 2026-08 (AGENTOOLS P4.2/P4.3/P4.7/P4.8): параллельный авторинг по замороженному формату и D139 + ревью + последовательное закрытие по plan-protocol §8',
  whenToUse: 'Фаза P4 плана AGENTOOLS, после закрытия P4.1, P4.6 и solo-item P4.4. Запуск — только по «Запускай» владельца',
  phases: [
    { title: 'Precheck', detail: 'предусловия: ГЕЙТ A2, rules.json, P4.1, P4.6 и P4.4 закрыты' },
    { title: 'Author', detail: 'параллельный авторинг векторов по своим документам' },
    { title: 'Review', detail: 'ревью по линзам + адверсариальная верификация Major+' },
    { title: 'Fix', detail: 'фиксы подтверждённых находок' },
    { title: 'Close', detail: 'ПОСЛЕДОВАТЕЛЬНО: item-коммит + бухгалтерия + handoff, item за item' },
  ],
}

// ── Константы ──────────────────────────────────────────────────────────────
const VAULT = '/home/vostrikov/Vaults/Brain'
const PLAN_REL = '05-Projects/03-Packages/AgenTools/plans/AGENTOOLS'
const PLAN = `${VAULT}/${PLAN_REL}`
const REPO = '/home/vostrikov/projects/packages/agentools'
const LINT = 'python3 /home/vostrikov/projects/packages/swissknifeman/packages/task/scripts/plan-lint.py'

// Роутинг — зеркало plan.md §3 Routing (SSOT — там; здесь детерминизм скрипта).
// implementation/high → sonnet/high (Meta: Target Operator Models); docs-curator → sonnet/low.
const ROUTE_AUTHOR = { model: 'sonnet', effort: 'high' }
const ROUTE_REVIEW = { model: 'sonnet', effort: 'high' }
const ROUTE_CLOSE = { model: 'sonnet', effort: 'low' }

// Волна авторинга. Порядок в массиве = порядок ПОСЛЕДОВАТЕЛЬНОГО закрытия (стадия Close).
const WAVE = [
  { id: 'P4.2', docs: '01_extension-identity.md, 02_tool-contract-profile.md, 03_action-lifecycle.md, 04_confirmation.md, 05_clarification-mrtr.md', dirs: 'fixtures/2026-08/vectors/l1|l2|l3' },
  { id: 'P4.3', docs: '08_audit-observability.md, 09_idempotency-errors.md + golden JCS/идемпотентности', dirs: 'fixtures/2026-08/vectors/l1|l2, fixtures/2026-08/golden' },
  { id: 'P4.7', docs: '06_scenarios.md + schema/expression.md, 07_long-operations.md, 10_security.md', dirs: 'fixtures/2026-08/vectors/l1|l2|l3' },
  { id: 'P4.8', docs: '00_overview.md (artifact), 11_conformance-harness.md (harness)', dirs: 'fixtures/2026-08/static, fixtures/2026-08/harness' },
]

// ── Схемы structured output ────────────────────────────────────────────────
const PRECHECK_SCHEMA = {
  type: 'object', required: ['ok', 'reasons', 'items'], additionalProperties: false,
  properties: {
    ok: { type: 'boolean' },
    reasons: { type: 'array', items: { type: 'string' }, description: 'почему не ok; пусто при ok' },
    items: {
      type: 'array',
      description: 'фактические статусы items фазы P4 (байт-в-байт из поля **Status:**)',
      items: {
        type: 'object', required: ['id', 'status'], additionalProperties: false,
        properties: { id: { type: 'string' }, status: { type: 'string' } },
      },
    },
  },
}
const AUTHOR_SCHEMA = {
  type: 'object', required: ['status', 'summary', 'files', 'validation', 'covered', 'escalation', 'pending'],
  additionalProperties: false,
  properties: {
    status: { enum: ['done', 'blocked'] },
    summary: { type: 'string', description: 'Факты для Completion Notes, 3–8 строк' },
    files: { type: 'array', items: { type: 'string' }, description: 'созданные/изменённые файлы (репо-относительные)' },
    covered: { type: 'array', items: { type: 'string' }, description: 'покрытые маркеры правил' },
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
  type: 'object', required: ['status', 'lint', 'item_commit', 'bookkeeping_commit', 'notes'], additionalProperties: false,
  properties: {
    status: { enum: ['ok', 'fail'] }, lint: { enum: ['ok', 'fail'] },
    item_commit: { type: 'string', description: 'хеш item-коммита в репо agentools (реальный, из git rev-parse)' },
    bookkeeping_commit: { type: 'string', description: 'хеш bookkeeping-коммита в vault' },
    notes: { type: 'string' },
  },
}

// ── Общий преамбул агентов ─────────────────────────────────────────────────
const COMMON = `Дом кода: ${REPO} (git-репо пакета AgenTools). Дом плана: ${PLAN} (vault Brain, git-корень ${VAULT}).
Жёсткие правила plan-protocol:
- Исполнять СТРОГО Scope Included item'а; «улучшить заодно» — только строкой в pending, НЕ делать.
- План разошёлся с реальностью (правила нет в rules.json, файла нет) → НЕ импровизировать: вернуть escalation.
- Решения DR-01 и DR-02 НЕ пересматривать; расхождение правила с реализуемостью вектора = дефект правила → эскалация, не правка текста ревизии.
- Тексты (title векторов, README, комментарии) — русские; идентификаторы, нормативные слова и имена полей — английские, из схем и документов ревизии.
- Имена полей и значения перечислений НЕ изобретать: источник — spec/revisions/2026-08/**.`

// ── Промпты ────────────────────────────────────────────────────────────────
const precheckPrompt = () => `Read-only проверка предусловий волны авторинга фазы P4 плана AGENTOOLS.
Проверь и верни структурированный ответ:
1) ${PLAN}/phases/P4.md — фактические статусы ВСЕХ items фазы (поле «**Status:**», байт-в-байт).
2) P4.1, P4.6 и P4.4 обязаны быть терминальными (🟢 Done либо 🟠 Done with deviations) — иначе ok:false с причиной.
3) В ${REPO} обязаны существовать: spec/revisions/2026-08/rules.json (непустой список rules), fixtures/2026-08/schema/vector.json, fixtures/2026-08/schema/static-check.json, fixtures/2026-08/schema/harness-case.json, fixtures/2026-08/README.md.
4) \`cd ${REPO} && python3 tools/spec_lint.py spec/revisions/2026-08 --allow-missing\` → exit 0; \`python3 tools/spec_lint.py --fixtures-check fixtures/2026-08\` → exit 0.
5) \`cd ${REPO} && git status --short\` — рабочее дерево обязано быть чистым (волна пишет в общее дерево; посторонний дифф сделал бы закрытие items неразличимым) → иначе ok:false.
6) ${PLAN}/artifacts/P3-gate-a2.md содержит строку «ГЕЙТ A2: PASS».
Ничего не менять и не коммитить. ok:true только если ВСЕ шесть пунктов сошлись.`

const authorPrompt = (it) => `${COMMON}

Ты — автор item ${it.id} плана AGENTOOLS (волна авторинга тест-векторов ревизии 2026-08).
Порядок чтения (ровно такой): 1) ${PLAN}/plan.md — §2 Execution Rules, §5 Decision Log; 2) ${PLAN}/phases/P4.md — Phase Context ЦЕЛИКОМ (там же команда локального покрытия COV) + item ${it.id} ЦЕЛИКОМ; 3) файлы из Required Reads item'а в указанном порядке. Сверх этого не читать.
Твои документы: ${it.docs}. Твои каталоги: ${it.dirs}.

ВАЖНО — ты работаешь В ОБЩЕМ рабочем дереве параллельно максимум с тремя другими авторами:
- пиши ТОЛЬКО в файлы своих каталогов; ни одного файла вне поля Files своего item'а;
- НЕ делай git-операций вообще (ни add, ни commit, ни stash): коммитит отдельная последовательная стадия;
- НЕ трогай файлы плана в vault (статусы, Update Log, handoff, journal) — их пишет close-агент;
- не суди о своей работе по \`git status\`/\`git diff\`: дифф дерева может быть смешан из четырёх items.

Выполни работу item'а по его ТЗ (Scope Included / Implementation Rules / Code Guidance — жёсткие рамки), затем прогони КАЖДУЮ команду из поля Validation (кроме планового линтера — его гоняет close-агент) и зафиксируй результат дословно.
Ответ строго по схеме: status done|blocked · summary (факты для Completion Notes) · files · covered (покрытые маркеры правил) · validation (cmd → passed/failed + суть) · escalation (null | {reason, trigger}) · pending.`

const reviewPrompt = (it, lens) => `${COMMON}

Ты — адверсариальный ревьюер item ${it.id} плана AGENTOOLS, линза: **${lens}**.
Прочитай ТЗ item'а (${PLAN}/phases/P4.md, item ${it.id}) и созданные им файлы — по путям из поля Files item'а (НЕ по \`git diff\`: рабочее дерево может содержать работу четырёх items сразу). Read-only: ничего не менять, git не трогать.
Ищи ТОЛЬКО реальные дефекты по своей линзе. Severity: Blocker (вектор не различает то, что заявляет / ссылка на несуществующее правило) / Major (расхождение с ТЗ, форматом или правилом ревизии) / Minor / Nit.
Ответ по схеме findings; пустой список — легитимный результат.`

const verifyPrompt = (it, f) => `Адверсариально проверь находку ревью item ${it.id} (попробуй ОПРОВЕРГНУТЬ): «[${f.severity}] ${f.where}: ${f.summary}». Прочитай указанное место в ${REPO} / ${PLAN} и реши: находка реальна или ложная тревога? При неуверенности — isReal:false. Ответ по схеме.`

const fixPrompt = (it, findings) => `${COMMON}

Ты — фиксер item ${it.id} плана AGENTOOLS. Исправь ТОЛЬКО перечисленные подтверждённые находки ревью (ничего сверх):
${findings.map((f, i) => `${i + 1}. [${f.severity}] ${f.where}: ${f.summary} — hint: ${f.fix_hint}`).join('\n')}
Контекст: ТЗ item'а — ${PLAN}/phases/P4.md. Пиши только в файлы своих каталогов (${it.dirs}); git не трогай. Прогони Validation item'а заново.
Ответ по той же схеме, что у автора (status/summary/files/covered/validation/escalation/pending).`

const closePrompt = (it, ex) => `${COMMON}

Ты — close-агент item ${it.id} плана AGENTOOLS. Только механика закрытия по plan-protocol §8, НЕ дизайн и НЕ авторинг.
Данные автора:
- summary: ${ex.summary}
- files: ${ex.files.join(', ') || '—'}
- covered: ${(ex.covered || []).join(', ') || '—'}
- validation: ${ex.validation.map(v => `${v.cmd} → ${v.result}`).join(' · ')}
- pending: ${(ex.pending || []).join('; ') || '—'}

Шаги строго по порядку:
1) ШАГ 1 — item-коммит в ${REPO}: \`git add\` ТОЛЬКО по путям из поля Files item'а ${it.id} (перечислены в ${PLAN}/phases/P4.md). \`git add -A\` и \`git commit -a\` ЗАПРЕЩЕНЫ: в дереве лежит работа других items волны. Коммит — Conventional Commits по-русски, формат «test(fixtures): <суть> (${it.id})». Затем возьми РЕАЛЬНЫЙ хеш: \`git -C ${REPO} rev-parse --short HEAD\`.
   Если после \`git add\` в индекс попал файл, которого нет в Files item'а — НЕ коммить, вернуть status:fail с именем файла.
2) ШАГ 2 — бухгалтерия в ${PLAN}: phases/P4.md item ${it.id}: Status → «🟢 Done» (байт-в-байт) и строка в «## Phase Status» (Updated — сегодняшняя дата из \`date +%F\`); Completion Notes ← факты автора + перечень покрытых маркеров + каноническая строка «write-site: pre_mutation: <код> · pre_final: <код>» (коды — из фактического вызова компилятора Task Contract, не из головы); Pending Work ← pending (или «—»); Known Deviations — только при failed-пунктах Validation либо расхождении с §3 Routing. В plan.md: счётчик фазы P4 в «## 4. Phase Index & Status Board» и строка в «## 6. Update Log» формата «${it.id} закрыт: <суть 1 строка> — детали см. phases/P4.md ${it.id} Completion Notes» (≤300 символов); поле «Last Updated» в §0 Meta.
3) ШАГ 3 — перезапиши ${PLAN}/handoff.md целиком (заголовок «# HANDOFF — <ISO-дата> — after ${it.id}», Next в форме launch-block из enum'а протокола, хеш item-коммита из шага 1 — РЕАЛЬНЫЙ, не заглушка).
4) ШАГ 4 — append строки в ${PLAN}/journal.jsonl: \`cd ${PLAN} && python3 -m task.journal append --plan-dir . --plan AGENTOOLS --item ${it.id} --command plan-exec --actor sonnet/high --result done --note "<суть>"\` (если модуль недоступен — записать факт в notes, строку НЕ подделывать).
5) ШАГ 5 — \`cd ${VAULT} && ${LINT} ${PLAN_REL}\`; красное от диффа этого item'а почини (в рамках механики), счёт опубликуй в notes с атрибуцией «N ERROR / M WARN — новых от диффа item'а: K».
6) ШАГ 6 — bookkeeping-коммит в ${VAULT}: \`git add\` ТОЛЬКО ${PLAN_REL} (файлы плана AGENTOOLS), сообщение «docs(plan): AGENTOOLS ${it.id} закрыт — <суть>». Бухгалтерию любого другого плана в этот коммит не тянуть (D48).
Ответ по схеме: status/lint/item_commit/bookkeeping_commit/notes.`

const blockPrompt = (it, ex) => `В ${PLAN}/phases/P4.md item ${it.id}: Status → «🔴 Blocked» (и строка в «## Phase Status»), добавь в тело item'а blockquote «> 🔴 ESCALATION ${it.id}: ${ex.escalation?.reason || ex.summary} | trigger: ${ex.escalation?.trigger || 'неясные acceptance'} | нужен: plan-design», поле «**Escalation Needed:** yes». Обнови ${PLAN}/handoff.md (Next: design-item: task:plan-design AGENTOOLS ${it.id}, с блоком РЕЖИМ/ВХОД/СКОУП/НЕ ТРОГАТЬ). Коммит в vault: «docs(plan): AGENTOOLS ${it.id} заблокирован». Файлы репо ${REPO} не коммитить. Ответ: «ok».`

// ── Линзы ревью ────────────────────────────────────────────────────────────
const LENSES = [
  'контрактная строгость: каждый вектор структурно валиден против схемы своего класса, spec_rule_ids существуют в rules.json и не превышают 8, spec_rev = 2026-08, conformance_level = максимуму уровней покрываемых правил, фрагменты common/ скопированы без изменений',
  'различающая сила: каждый вектор ДЕЙСТВИТЕЛЬНО различает правила, на которые ссылается (у негативного есть парный позитивный либо expect.error; GREASE отличается ожиданиями от базового; artifact-вектор несёт подстроку диагностики) — ложная ссылка на правило есть обман гейта покрытия',
]

// ── Основной цикл ──────────────────────────────────────────────────────────
// args (необязателен): {items: ['P4.2','P4.7']} — прогнать подмножество волны.
const ARGS = (typeof args === 'string') ? JSON.parse(args) : (args || {})
const SELECTED = ARGS.items ? WAVE.filter(w => ARGS.items.includes(w.id)) : WAVE
const report = { precheck: null, authored: [], closed: [], stopped: null }

phase('Precheck')
const pre = await agent(precheckPrompt(), { label: 'precheck:P4', model: 'sonnet', effort: 'low', schema: PRECHECK_SCHEMA, phase: 'Precheck' })
report.precheck = pre
if (!pre || !pre.ok) {
  report.stopped = `предусловия не выполнены: ${(pre?.reasons || ['precheck-агент не вернул результат']).join(' · ')}`
  log(`СТОП — ${report.stopped}`)
  return report
}
const done = new Set(pre.items.filter(i => i.status.includes('Done')).map(i => i.id))
const todo = SELECTED.filter(w => !done.has(w.id))
log(`волна авторинга: к исполнению ${todo.length}/${SELECTED.length} items (уже терминальны: ${SELECTED.length - todo.length})`)
if (!todo.length) { report.stopped = 'все items волны уже терминальны'; return report }

// Авторинг → ревью → верификация → фикс. Pipeline: ревью item'а стартует, как только он написан.
const authored = await pipeline(
  todo,
  (it) => agent(authorPrompt(it), { label: `author:${it.id}`, phase: 'Author', schema: AUTHOR_SCHEMA, model: ROUTE_AUTHOR.model, effort: ROUTE_AUTHOR.effort })
    .then(ex => ({ it, ex })),
  async ({ it, ex }) => {
    if (!ex || ex.status === 'blocked' || ex.escalation) return { it, ex, blocked: true }
    const extra = []
    for (let round = 1; round <= 2; round++) {
      const found = (await parallel(LENSES.map(l => () =>
        agent(reviewPrompt(it, l), { label: `review:${it.id}:r${round}`, phase: 'Review', schema: FINDINGS_SCHEMA, model: ROUTE_REVIEW.model, effort: ROUTE_REVIEW.effort })
      ))).filter(Boolean).flatMap(r => r.findings)
      if (!found.length) break
      const serious = found.filter(f => f.severity === 'Blocker' || f.severity === 'Major')
      extra.push(...found.filter(f => f.severity === 'Minor' || f.severity === 'Nit')
        .map(f => `ревью r${round} [${f.severity}] ${f.where}: ${f.summary}`))
      if (!serious.length) break
      const confirmed = (await parallel(serious.map(f => () =>
        agent(verifyPrompt(it, f), { label: `verify:${it.id}:r${round}`, phase: 'Review', schema: VERDICT_SCHEMA, model: ROUTE_REVIEW.model, effort: 'high' })
          .then(v => ({ f, v }))
      ))).filter(Boolean).filter(x => x.v?.isReal).map(x => x.f)
      if (!confirmed.length) break
      const fixed = await agent(fixPrompt(it, confirmed), { label: `fix:${it.id}:r${round}`, phase: 'Fix', schema: AUTHOR_SCHEMA, model: ROUTE_AUTHOR.model, effort: ROUTE_AUTHOR.effort })
      if (fixed) { ex = fixed }
    }
    ex.pending = [...(ex.pending || []), ...extra]
    return { it, ex, blocked: false }
  },
)

// Закрытие — СТРОГО последовательно: два коммита на item в двух репозиториях (plan-protocol §8),
// параллельное закрытие дралось бы за .git/index.lock и за общие файлы плана.
phase('Close')
for (const r of authored.filter(Boolean)) {
  if (r.blocked || !r.ex) {
    await agent(blockPrompt(r.it, r.ex || { summary: 'автор не вернул результат' }), { label: `block:${r.it.id}`, phase: 'Close', model: ROUTE_CLOSE.model, effort: ROUTE_CLOSE.effort })
    report.stopped = `ESCALATION-REQUIRED: ${r.it.id} — ${r.ex?.escalation?.reason || 'автор вернул blocked'}`
    log(`СТОП на ${r.it.id}: ${report.stopped}`)
    break
  }
  report.authored.push({ id: r.it.id, covered: (r.ex.covered || []).length, files: (r.ex.files || []).length })
  const cl = await agent(closePrompt(r.it, r.ex), { label: `close:${r.it.id}`, phase: 'Close', schema: CLOSE_SCHEMA, model: ROUTE_CLOSE.model, effort: ROUTE_CLOSE.effort })
  if (!cl || cl.status !== 'ok') {
    report.stopped = `закрытие ${r.it.id} не прошло: ${cl?.notes || 'close-агент не вернул результат'}`
    log(`СТОП — ${report.stopped}`)
    break
  }
  report.closed.push({ id: r.it.id, item_commit: cl.item_commit, bookkeeping_commit: cl.bookkeeping_commit, lint: cl.lint })
  log(`${r.it.id} закрыт: ${cl.item_commit} · lint ${cl.lint}`)
}

log(report.stopped
  ? `волна остановлена: ${report.stopped}. Следующий шаг — разобрать причину и добить оставшиеся items вручную либо resume этого скрипта.`
  : `волна закрыта: ${report.closed.length} items. Следующий шаг — item P4.5 (двунаправленный линтер + ГЕЙТ A3), solo.`)
return report
