// W2 — параллельная ремедиация Gate B round 1 для AGENTOOLS P4.
//
// Предусловия: P3, P4.14 и P4.9 терминальны; рабочее дерево чисто; fixtures-check зелёный, а
// полный lint до P4.12 имеет ровно ожидаемый единственный debt CFH-55 (D149/D154). Скрипт
// исполняет только P4.10–P4.12. P4.13 (интеграционный A3) остаётся solo.
// Agents не коммитят план параллельно: только авторинг идёт параллельно; общие гейты, review и
// закрытие идут после join последовательно под implementation/high.

export const meta = {
  name: 'agentools-p4-remediation',
  description: 'W2: различающие behavior/scenario/artifact carriers по F1–F6 Gate B',
  whenToUse: 'Только после терминальности AGENTOOLS P3, P4.14 и P4.9; затем закрыть P4.13 solo',
  phases: [
    { title: 'Precheck', detail: 'P3/P4.14/P4.9 terminal, only CFH-55 debt и fixtures-check зелёный, git clean' },
    { title: 'Author', detail: 'P4.10/P4.11/P4.12 параллельно, непересекающиеся предметы' },
    { title: 'Review', detail: 'после join: адресная проверка различающей силы и negative/positive pairs' },
    { title: 'Close', detail: 'implementation/high: P4.12 → P4.10 → P4.11, полные гейты и §8' },
  ],
}

const ROOT = '/home/vostrikov/projects/packages/agentools'
const PLAN = `${ROOT}/plans/AGENTOOLS`
const ROUTE = { model: 'gpt-5.6-terra', effort: 'high' }
const MAX_REVIEW_FIX_ITERATIONS = 1

const WAVE = [
  {
    id: 'P4.10',
    subject: 'F1/F2/F4 — security/lifecycle/confirmation/clarification/longop/audit behavior carriers',
    files: 'fixtures/2026-08/vectors/l1/{security,confpolicy,conftoken,lifecycleconcurrency,lifecyclefold,profileuntrusted}-*.json · fixtures/2026-08/vectors/l2/{clarificationstate,longop}-*.json · fixtures/2026-08/vectors/l3/{audit,confdual,lifecycleintegrity}-*.json',
  },
  {
    id: 'P4.11',
    subject: 'F5 — parse/order/host-code/AST-limit scenario carriers',
    files: 'fixtures/2026-08/vectors/l2/{scnorder-002,scnnocode-003,scenario-004,scnforward-013,scnhostcode-014,scnlimit-015}.json',
  },
  {
    id: 'P4.12',
    subject: 'F3/F6/F8(measurable) + CFH-55 — static pairs, checker branches и harness pair',
    files: 'tools/spec_lint.py · fixtures/2026-08/schema/harness-case.json · fixtures/2026-08/harness/{hmcpcode-001,hmcpcodebad-001}.json · fixtures/2026-08/static/{extidentity,extnegotiation,toolcontract,tooldigest,toolauthoring,lifecycleindex,ovrdev,cfhbaseline,cfhreport,cfhgrease,tooldescription}-*.json',
  },
]
const CLOSE_ORDER = ['P4.12', 'P4.10', 'P4.11']

const PRECHECK_SCHEMA = {
  type: 'object', required: ['ok', 'reasons'], additionalProperties: false,
  properties: { ok: { type: 'boolean' }, reasons: { type: 'array', items: { type: 'string' } } },
}
const RESULT_SCHEMA = {
  type: 'object', required: ['status', 'summary', 'files', 'validation', 'covered_rules', 'pending', 'load_bearing', 'failure_scenario', 'tests_green'],
  additionalProperties: false,
  properties: {
    status: { enum: ['ready', 'blocked'] }, summary: { type: 'string' },
    files: { type: 'array', items: { type: 'string' } },
    validation: { type: 'array', items: { type: 'string' } },
    covered_rules: { type: 'array', items: { type: 'string' } },
    pending: { type: 'array', items: { type: 'string' } },
    load_bearing: { type: 'array', items: { type: 'string' } },
    failure_scenario: { type: 'string' },
    tests_green: { type: 'boolean', description: 'item-local проверки зелёные; общие gates перечислены в pending' },
  },
}
const REVIEW_SCHEMA = {
  type: 'object', required: ['findings', 'verdict'], additionalProperties: false,
  properties: {
    findings: { type: 'array', items: {
      type: 'object', required: ['severity', 'where', 'why', 'fix'], additionalProperties: false,
      properties: {
        severity: { enum: ['Blocker', 'Major', 'Minor', 'Nit'] }, where: { type: 'string' },
        why: { type: 'string' }, fix: { type: 'string' },
      },
    } },
    verdict: { enum: ['APPROVE', 'FIX_REQUIRED'] },
  },
}
const CLOSE_SCHEMA = {
  type: 'object', required: ['status', 'item_commit', 'bookkeeping_commit', 'validation', 'failure_scenario'],
  additionalProperties: false,
  properties: {
    status: { enum: ['closed', 'blocked'] },
    item_commit: { type: 'string' }, bookkeeping_commit: { type: 'string' },
    validation: { type: 'array', items: { type: 'string' } },
    failure_scenario: { type: 'string' },
  },
}

const precheckPrompt = `Read-only precheck W2 AGENTOOLS. Прочитай ${PLAN}/plan.md §4,
${PLAN}/phases/P3.md и ${PLAN}/phases/P4.md. P3 обязана быть terminal (green либо orange),
P4.14 и P4.9 terminal; git -C ${ROOT} status --short пуст.
До P4.12 полный python3 ${ROOT}/tools/spec_lint.py ${ROOT}/spec/revisions/2026-08 обязан
завершиться exit 1 и назвать РОВНО одну ошибку: MUST-правило CFH-55 не имеет покрывающего вектора;
любая другая ошибка — fail. python3 ${ROOT}/tools/spec_lint.py --fixtures-check
${ROOT}/fixtures/2026-08 обязан дать exit 0. Ничего не менять. ok=true только при всех условиях.`

const authorPrompt = (item) => `Ты исполняешь ${item.id} плана AGENTOOLS: ${item.subject}.
Порядок чтения: 1) ${PLAN}/research/12-p3-p4-gate-b-remediation-2026-08.md 2)
${PLAN}/artifacts/P5-gate-b.md — F1–F6 и полная матрица 3) ${PLAN}/phases/P4.md — item
${item.id} целиком и Required Reads в его порядке. Пиши только ${item.files}. Git и файлы плана
не трогай: дерево общее с другими авторами. Никакой подгонки spec_rule_ids; каждый negative
carrier обязан иметь формальный stimulus и различимый predicate/парный positive. Выполни только
item-local проверки, не зависящие от параллельно меняемого tools/spec_lint.py; общие fixtures,
coverage и full-lint gates перечисли в pending для sequential closer после join. status=ready
означает «authoring закончен», не «item закрыт». Конфликт с нормативным текстом → status=blocked,
не самовольная правка P3. tests_green относится только к item-local проверкам; load_bearing
перечисляет реально различаемые нормы, failure_scenario объясняет, что сломается при ложном PASS.
Финальный текст = данные по RESULT_SCHEMA, не сообщение человеку.`

const reviewPrompt = (item, result) => `Read-only adversarial review ${item.id}. ТЗ:
${PLAN}/phases/P4.md; находки round 1: ${PLAN}/artifacts/P5-gate-b.md; изменённые файлы:
${result.files.join(', ')}. Проверь: stimulus выводим из given/script; error/diagnostic адресен;
каждый spec_rule_id реально различается; negative имеет positive pair; правильная реализация не
падает на кейсе, предназначенном для accept. Ничего не менять. Major/Blocker перечисли точно;
verdict=FIX_REQUIRED при любом Major/Blocker. Финальный текст = данные по REVIEW_SCHEMA.`

const fixPrompt = (item, findings) => `Исправь только подтверждённые Major/Blocker ${item.id}:
${findings.map((f, n) => `${n + 1}. ${f.where}: ${f.why}; fix=${f.fix}`).join('\n')}
ТЗ и запреты — ${PLAN}/phases/P4.md ${item.id}. Пиши только Files item'а, git/план не трогай.
Перепрогони item-local проверки и верни RESULT_SCHEMA со status=ready. Финальный текст = данные
по RESULT_SCHEMA, не сообщение человеку.`

const closePrompt = (item, result) => `Ты implementation/high executor. Последовательно закрой
${item.id} по plan-protocol §8 после parallel-authoring join.
Вход автора: ${JSON.stringify(result)}. Прочитай ${PLAN}/phases/P4.md ${item.id} и plan.md §2/§3.
Сначала перепроверь ПОЛНУЮ Validation item'а на joined tree; ожидаемые foreign diffs других W2
items назови и не коммить. Затем: 1) journal start с actual route implementation/high; 2) git add
только фактические пути из Files item'а, item commit; 3) bookkeeping Status, Completion Notes,
write-site codes, Phase Status, Status Board, Update Log, terminal journal; 4) handoff с реальным
hash; 5) plan-lint final tree; 6) bookkeeping commit только plan files. git add -A/-a запрещены.
При красном гейте не закрывать и вернуть BLOCKED. Historical reported count 32 не дополнять
фиктивными carriers: exact contract D154 = 27 fixture ids / 116 FAIL rules. Финальный текст =
данные по CLOSE_SCHEMA, status=blocked обязан назвать concrete failure_scenario.`

async function safeAgent(prompt, opts) {
  try { return await agent(prompt, opts) }
  catch (e) { log(`agent error (${opts.label || '?'}): ${e.message || e}`); return null }
}

phase('Precheck')
const pre = await safeAgent(precheckPrompt, { label: 'precheck:W2', model: ROUTE.model, effort: 'low', schema: PRECHECK_SCHEMA })
if (!pre?.ok) return { stopped: true, reasons: pre?.reasons || ['precheck failed'] }

phase('Author')
const authored = await parallel(WAVE.map(item => () =>
  agent(authorPrompt(item), { label: `author:${item.id}`, model: ROUTE.model, effort: ROUTE.effort, schema: RESULT_SCHEMA })
    .then(result => ({ item, result }))
))

phase('Review')
const reviewed = []
for (const entry of authored.filter(Boolean)) {
  if (entry.result?.status !== 'ready') return { stopped: true, item: entry.item.id, result: entry.result }
  let result = entry.result
  let accepted = false
  for (let iteration = 0; iteration <= MAX_REVIEW_FIX_ITERATIONS; iteration++) {
    const review = await safeAgent(reviewPrompt(entry.item, result), {
      label: `review:${entry.item.id}#${iteration}`, model: ROUTE.model, effort: 'high', schema: REVIEW_SCHEMA,
    })
    if (!review) return { stopped: true, item: entry.item.id, result: 'review returned null' }
    const serious = (review.findings || []).filter(f => f.severity === 'Blocker' || f.severity === 'Major')
    if (!serious.length && review.verdict === 'APPROVE') { accepted = true; break }
    if (iteration === MAX_REVIEW_FIX_ITERATIONS) {
      return { stopped: true, item: entry.item.id, result: 'review-fix cap exhausted', findings: serious }
    }
    result = await safeAgent(fixPrompt(entry.item, serious), {
      label: `fix:${entry.item.id}#${iteration + 1}`, model: ROUTE.model, effort: ROUTE.effort, schema: RESULT_SCHEMA,
    })
    if (result?.status !== 'ready' || !result.tests_green) return { stopped: true, item: entry.item.id, result }
  }
  if (!accepted) return { stopped: true, item: entry.item.id, result: 'review did not approve' }
  reviewed.push({ item: entry.item, result })
}

phase('Close')
const closed = []
for (const itemId of CLOSE_ORDER) {
  const entry = reviewed.find(candidate => candidate.item.id === itemId)
  if (!entry) return { stopped: true, item: itemId, result: 'missing reviewed author result' }
  const result = await safeAgent(closePrompt(entry.item, entry.result), {
    label: `close:${entry.item.id}`, model: ROUTE.model, effort: ROUTE.effort, schema: CLOSE_SCHEMA,
  })
  if (result?.status !== 'closed') return { stopped: true, item: entry.item.id, result: result || 'close returned null' }
  closed.push({ item: entry.item.id, result })
}

log(`W2 закрыла ${closed.length}/${WAVE.length}; следующий шаг — solo P4.13`)
return { stopped: false, closed, next: 'run-items: task:plan-run AGENTOOLS P4.13' }
