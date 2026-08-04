// W2 — параллельная ремедиация Gate B round 1 для AGENTOOLS P4.
//
// Предусловия: P3.19 и P4.9 терминальны; рабочее дерево чисто; fixtures-check зелёный, а полный
// lint до P4.12 имеет ровно ожидаемый единственный debt CFH-55 (D149). Скрипт исполняет только
// P4.10–P4.12. P4.13 (интеграционный A3) остаётся solo.
// Agents не коммитят план параллельно: авторинг/ревью идут параллельно, закрытие — последовательно.

export const meta = {
  name: 'agentools-p4-remediation',
  description: 'W2: различающие behavior/scenario/artifact carriers по F1–F6 Gate B',
  whenToUse: 'Только после терминальности AGENTOOLS P3.19 и P4.9; затем закрыть P4.13 solo',
  phases: [
    { title: 'Precheck', detail: 'P3.19/P4.9 terminal, only CFH-55 debt и fixtures-check зелёный, git clean' },
    { title: 'Author', detail: 'P4.10/P4.11/P4.12 параллельно, непересекающиеся предметы' },
    { title: 'Review', detail: 'адверсариальная проверка различающей силы и negative/positive pairs' },
    { title: 'Close', detail: 'последовательное закрытие items по plan-protocol §8' },
  ],
}

const ROOT = '/home/vostrikov/projects/packages/agentools'
const PLAN = `${ROOT}/plans/AGENTOOLS`
const ROUTE = { model: 'gpt-5.6-terra', effort: 'high' }
const CLOSE_ROUTE = { model: 'gpt-5.6-terra', effort: 'low' }

const WAVE = [
  {
    id: 'P4.10',
    subject: 'F1/F2/F4 — security/lifecycle/confirmation/clarification/longop/audit behavior carriers',
    files: 'fixtures/2026-08/vectors/{l1,l2,l3}/**',
  },
  {
    id: 'P4.11',
    subject: 'F5 — parse/order/host-code/AST-limit scenario carriers',
    files: 'fixtures/2026-08/vectors/l2/**',
  },
  {
    id: 'P4.12',
    subject: 'F3/F6/F8(measurable) — static pairs и checker branches',
    files: 'fixtures/2026-08/static/** · tools/spec_lint.py',
  },
]

const PRECHECK_SCHEMA = {
  type: 'object', required: ['ok', 'reasons'], additionalProperties: false,
  properties: { ok: { type: 'boolean' }, reasons: { type: 'array', items: { type: 'string' } } },
}
const RESULT_SCHEMA = {
  type: 'object', required: ['status', 'summary', 'files', 'validation', 'covered_rules', 'pending'],
  additionalProperties: false,
  properties: {
    status: { enum: ['done', 'blocked'] }, summary: { type: 'string' },
    files: { type: 'array', items: { type: 'string' } },
    validation: { type: 'array', items: { type: 'string' } },
    covered_rules: { type: 'array', items: { type: 'string' } },
    pending: { type: 'array', items: { type: 'string' } },
  },
}
const REVIEW_SCHEMA = {
  type: 'object', required: ['findings'], additionalProperties: false,
  properties: {
    findings: { type: 'array', items: {
      type: 'object', required: ['severity', 'where', 'why', 'fix'], additionalProperties: false,
      properties: {
        severity: { enum: ['Blocker', 'Major', 'Minor', 'Nit'] }, where: { type: 'string' },
        why: { type: 'string' }, fix: { type: 'string' },
      },
    } },
  },
}

const precheckPrompt = `Read-only precheck W2 AGENTOOLS. Прочитай ${PLAN}/phases/P3.md и
${PLAN}/phases/P4.md. P3.19 и P4.9 обязаны быть terminal; git -C ${ROOT} status --short пуст.
До P4.12 полный python3 ${ROOT}/tools/spec_lint.py ${ROOT}/spec/revisions/2026-08 обязан
завершиться exit 1 и назвать РОВНО одну ошибку: MUST-правило CFH-55 не имеет покрывающего вектора;
любая другая ошибка — fail. python3 ${ROOT}/tools/spec_lint.py --fixtures-check
${ROOT}/fixtures/2026-08 обязан дать exit 0. Ничего не менять. ok=true только при всех условиях.`

const authorPrompt = (item) => `Ты исполняешь ${item.id} плана AGENTOOLS: ${item.subject}.
Порядок чтения: 1) ${PLAN}/research/12-p3-p4-gate-b-remediation-2026-08.md 2)
${PLAN}/artifacts/P5-gate-b.md — F1–F6 и полная матрица 3) ${PLAN}/phases/P4.md — item
${item.id} целиком и Required Reads в его порядке. Пиши только ${item.files}. Git и файлы плана
не трогай: дерево общее с другими авторами. Никакой подгонки spec_rule_ids; каждый negative
carrier обязан иметь формальный stimulus и различимый predicate/парный positive. Выполни всю
Validation item'а, кроме plan-lint. Верни структурированный результат; конфликт с нормативным
текстом → status=blocked, не самовольная правка P3.`

const reviewPrompt = (item, result) => `Read-only adversarial review ${item.id}. ТЗ:
${PLAN}/phases/P4.md; находки round 1: ${PLAN}/artifacts/P5-gate-b.md; изменённые файлы:
${result.files.join(', ')}. Проверь: stimulus выводим из given/script; error/diagnostic адресен;
каждый spec_rule_id реально различается; negative имеет positive pair; правильная реализация не
падает на кейсе, предназначенном для accept. Ничего не менять. Major/Blocker перечисли точно.`

const fixPrompt = (item, findings) => `Исправь только подтверждённые Major/Blocker ${item.id}:
${findings.map((f, n) => `${n + 1}. ${f.where}: ${f.why}; fix=${f.fix}`).join('\n')}
ТЗ и запреты — ${PLAN}/phases/P4.md ${item.id}. Пиши только Files item'а, git/план не трогай.
Перепрогони Validation и верни RESULT_SCHEMA.`

const closePrompt = (item, result) => `Последовательно закрой ${item.id} по plan-protocol §8.
Вход автора: ${JSON.stringify(result)}. Прочитай ${PLAN}/phases/P4.md ${item.id} и plan.md §2/§3.
1) git add только фактические пути из Files item'а, validate, item commit; 2) bookkeeping Status,
Completion Notes, write-site codes, Phase Status, Status Board, Update Log, journal; 3) handoff с
реальным hash; 4) plan-lint final tree; 5) bookkeeping commit только plan files. git add -A/-a
запрещены. При красном гейте не закрывать и вернуть BLOCKED.`

phase('Precheck')
const pre = await agent(precheckPrompt, { label: 'precheck:W2', model: ROUTE.model, effort: 'low', schema: PRECHECK_SCHEMA })
if (!pre?.ok) return { stopped: true, reasons: pre?.reasons || ['precheck failed'] }

phase('Author')
const authored = await parallel(WAVE.map(item => () =>
  agent(authorPrompt(item), { label: `author:${item.id}`, model: ROUTE.model, effort: ROUTE.effort, schema: RESULT_SCHEMA })
    .then(result => ({ item, result }))
))

phase('Review')
const reviewed = []
for (const entry of authored.filter(Boolean)) {
  if (entry.result?.status !== 'done') return { stopped: true, item: entry.item.id, result: entry.result }
  const review = await agent(reviewPrompt(entry.item, entry.result), {
    label: `review:${entry.item.id}`, model: ROUTE.model, effort: 'high', schema: REVIEW_SCHEMA,
  })
  const serious = (review?.findings || []).filter(f => f.severity === 'Blocker' || f.severity === 'Major')
  const result = serious.length
    ? await agent(fixPrompt(entry.item, serious), { label: `fix:${entry.item.id}`, model: ROUTE.model, effort: ROUTE.effort, schema: RESULT_SCHEMA })
    : entry.result
  if (result?.status !== 'done') return { stopped: true, item: entry.item.id, result }
  reviewed.push({ item: entry.item, result })
}

phase('Close')
const closed = []
for (const entry of reviewed) {
  const result = await agent(closePrompt(entry.item, entry.result), {
    label: `close:${entry.item.id}`, model: CLOSE_ROUTE.model, effort: CLOSE_ROUTE.effort,
  })
  closed.push({ item: entry.item.id, result })
}

log(`W2 закрыла ${closed.length}/${WAVE.length}; следующий шаг — solo P4.13`)
return { stopped: false, closed, next: 'run-items: task:plan-run AGENTOOLS P4.13' }
