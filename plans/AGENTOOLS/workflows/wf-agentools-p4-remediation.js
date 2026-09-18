// W2 — параллельная ремедиация Gate B round 1 для AGENTOOLS P4.
//
// Предусловия: P3, P4.14, P4.9 и P4.15 терминальны. Fresh-режим требует чистое дерево,
// fixtures-check green и ровно один debt CFH-55. Resume-режим (D155) принимает только уже
// собранный незакоммиченный candidate P4.10–P4.12: все dirty paths лежат в их Files,
// представлены все три scope, fixtures/full lint green, plan files чисты. Скрипт исполняет
// только P4.10–P4.12. P4.13 (интеграционный A3) остаётся solo.
// Agents не коммитят план параллельно: только авторинг идёт параллельно; общие гейты, review и
// закрытие идут после join последовательно под implementation/high.
// workflow-craft: development pipeline = author-by-spec + dev-slice. Барьер перед review
// намеренный по D154: P4.12 меняет общий checker; обязательные impl/tests/review/gates/commit
// сохранены, review read-only, fix-loop ограничен MAX_REVIEW_FIX_ITERATIONS.

export const meta = {
  name: 'agentools-p4-remediation',
  description: 'W2: различающие behavior/scenario/artifact carriers по F1–F6 Gate B, exact rule evidence D156',
  whenToUse: 'Только после терминальности AGENTOOLS P3, P4.14, P4.9 и P4.15; затем закрыть P4.13 solo',
  phases: [
    { title: 'Precheck', detail: 'fresh: only CFH-55 + git clean; resume: W2-only dirty candidate + full gates green' },
    { title: 'Author', detail: 'P4.10: пять file-disjoint shards; P4.11/P4.12: отдельные authors; resume read-only' },
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
    subject: 'F1/F2/F4 — 65 single-rule behavior pairs + retained longop task-channel carrier (D156)',
    files: 'fixtures/2026-08/vectors/l1/{security,confpolicy,conftoken,lifecycleconcurrency,lifecyclefold}-*.json · fixtures/2026-08/vectors/l2/{clarificationstate,longop}-*.json · fixtures/2026-08/vectors/l3/{audit,confdual,lifecycleintegrity,profileuntrusted}-*.json',
  },
  {
    id: 'P4.11',
    subject: 'F5 — parse/order/host-code/AST-limit scenario carriers',
    files: 'fixtures/2026-08/vectors/l2/{scnorder-002,scnnocode-003,scenario-004,scnforward-013,scnhostcode-014,scnlimit-015}.json',
  },
  {
    id: 'P4.12',
    subject: 'F3/F6/F8(measurable) + SEC-17/AOB-26 static-transfer + CFH-55 — static pairs, checker branches и harness pair',
    files: 'tools/spec_lint.py · fixtures/2026-08/schema/harness-case.json · fixtures/2026-08/harness/{hmcpcode-001,hmcpcodebad-001}.json · fixtures/2026-08/static/{extidentity,extnegotiation,toolcontract,tooldigest,toolauthoring,lifecycleindex,ovrdev,cfhbaseline,cfhreport,cfhgrease,tooldescription,securityauthoring,auditschema}-*.json',
  },
]
const P410_SHARDS = [
  {
    id: 'security',
    subject: 'SEC-1…SEC-16, SEC-18…SEC-19 — token/provenance/principal/authorization behavior',
    files: 'fixtures/2026-08/vectors/l1/security-*.json',
  },
  {
    id: 'confirmation',
    subject: 'TLP-11 + CNF-3/4/5/6/8/13/21/23/33/34/35/36/38',
    files: 'fixtures/2026-08/vectors/l1/{confpolicy,conftoken}-*.json · fixtures/2026-08/vectors/l3/confdual-*.json',
  },
  {
    id: 'lifecycle',
    subject: 'ALC-2/3/4/6/7/15/16/18/19/20/21/22/23/25/26/27/28/30/31/33/34/35/36/37',
    files: 'fixtures/2026-08/vectors/l1/{lifecycleconcurrency,lifecyclefold}-*.json · fixtures/2026-08/vectors/l3/lifecycleintegrity-*.json',
  },
  {
    id: 'audit',
    subject: 'AOB-25, AOB-27…AOB-31 behavior (AOB-26 передан P4.12)',
    files: 'fixtures/2026-08/vectors/l3/audit-*.json',
  },
  {
    id: 'dialog',
    subject: 'CLR-2/8 + TLP-25 + retained longop-002/102 LOP-6/7/8',
    files: 'fixtures/2026-08/vectors/l2/{clarificationstate,longop}-*.json · fixtures/2026-08/vectors/l3/profileuntrusted-*.json',
  },
]
const CLOSE_ORDER = ['P4.12', 'P4.10', 'P4.11']

const PRECHECK_SCHEMA = {
  type: 'object', required: ['ok', 'mode', 'reasons', 'dirty_paths'], additionalProperties: false,
  properties: {
    ok: { type: 'boolean' }, mode: { enum: ['fresh', 'resume'] },
    reasons: { type: 'array', items: { type: 'string' } },
    dirty_paths: { type: 'array', items: { type: 'string' } },
  },
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
const P410_RESULT_SCHEMA = {
  ...RESULT_SCHEMA,
  required: [...RESULT_SCHEMA.required, 'rule_evidence', 'retained_carriers'],
  properties: {
    ...RESULT_SCHEMA.properties,
    rule_evidence: {
      type: 'array',
      items: {
        type: 'object',
        required: ['rule_id', 'source_carrier', 'negative', 'positive', 'stimulus_pointer', 'oracle_pointer', 'counterfactual'],
        additionalProperties: false,
        properties: {
          rule_id: { type: 'string' }, source_carrier: { type: 'string' },
          negative: { type: 'string' }, positive: { type: 'string' },
          stimulus_pointer: { type: 'string' }, oracle_pointer: { type: 'string' },
          counterfactual: { type: 'string' },
        },
      },
    },
    retained_carriers: {
      type: 'array',
      items: {
        type: 'object',
        required: ['source_carrier', 'negative', 'positive', 'why_retained'],
        additionalProperties: false,
        properties: {
          source_carrier: { type: 'string' }, negative: { type: 'string' },
          positive: { type: 'string' }, why_retained: { type: 'string' },
        },
      },
    },
  },
}
const resultSchemaFor = (item) => item.id === 'P4.10' ? P410_RESULT_SCHEMA : RESULT_SCHEMA
const unique = (values) => [...new Set(values.filter(Boolean))]
const uniqueObjects = (values) => {
  const seen = new Set()
  return values.filter(value => {
    const key = JSON.stringify(value)
    if (seen.has(key)) return false
    seen.add(key)
    return true
  })
}
const aggregateP410 = (parts) => {
  const byShard = new Map(parts.filter(Boolean).map(part => [part.shard.id, part.result]))
  const results = P410_SHARDS.map(shard => byShard.get(shard.id)).filter(Boolean)
  const missing = P410_SHARDS.filter(shard => !byShard.get(shard.id)).map(shard => shard.id)
  const blocked = P410_SHARDS.filter(shard => byShard.get(shard.id)?.status !== 'ready').map(shard => shard.id)
  return {
    status: missing.length || blocked.length ? 'blocked' : 'ready',
    summary: `P4.10 shards: ${P410_SHARDS.map(shard => `${shard.id}=${byShard.get(shard.id)?.status || 'missing'}`).join(', ')}`,
    files: unique(results.flatMap(result => result.files || [])),
    validation: unique(results.flatMap(result => result.validation || [])),
    covered_rules: unique(results.flatMap(result => result.covered_rules || [])),
    pending: unique([
      ...results.flatMap(result => result.pending || []),
      ...missing.map(id => `missing shard ${id}`),
      ...blocked.map(id => `blocked shard ${id}`),
    ]),
    load_bearing: unique(results.flatMap(result => result.load_bearing || [])),
    failure_scenario: results.map(result => result.failure_scenario).filter(Boolean).join(' | '),
    tests_green: results.length === P410_SHARDS.length && results.every(result => result.tests_green),
    rule_evidence: uniqueObjects(results.flatMap(result => result.rule_evidence || [])),
    retained_carriers: uniqueObjects(results.flatMap(result => result.retained_carriers || [])),
  }
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

const precheckPrompt = `Read-only precheck W2 AGENTOOLS по D155. Прочитай ${PLAN}/plan.md §4,
${PLAN}/phases/P3.md и ${PLAN}/phases/P4.md. P3 обязана быть terminal (green либо orange),
P4.14, P4.9 и P4.15 terminal. Получи exact git -C ${ROOT} status --short.
Режим fresh: status пуст; полный python3 ${ROOT}/tools/spec_lint.py
${ROOT}/spec/revisions/2026-08 завершён exit 1 и называет РОВНО одну ошибку — MUST-правило
CFH-55 без покрывающего вектора; fixtures-check exit 0.
Режим resume: status не пуст; КАЖДЫЙ dirty path входит в Files P4.10, P4.11 или P4.12,
ни один ${PLAN}/** не dirty, и представлены изменения всех трёх item scopes. В этом режиме
fixtures-check И полный lint без --allow-missing обязаны дать exit 0. Любой посторонний path,
неполный набор scopes или иной результат gate = fail closed: ok=false, mode=resume, причина
поимённо. Ничего не менять. Верни dirty_paths repo-relative; ok=true только при всех условиях
одного из двух режимов.`

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
${item.id === 'P4.10' ? `Дополнительно первым прочитай ${PLAN}/research/17-p4.10-behavior-carrier-dor-2026-08.md:
65 round-1 behavior FAIL rules получают ровно по одной single-rule pair; SEC-17/AOB-26 не
трогай — они переданы P4.12; longop-002/102 верни отдельно в retained_carriers. rule_evidence
обязан иметь exact-set 65/65 и разрешимые JSON Pointers.` : ''}
${item.id === 'P4.12' ? `Дополнительно прочитай ${PLAN}/research/17-p4.10-behavior-carrier-dor-2026-08.md §4:
создай static pairs securityauthoring для SEC-17 и auditschema для AOB-26; behavior fixtures
P4.10 не трогай.` : ''}
Финальный текст = данные по ${item.id === 'P4.10' ? 'P410_RESULT_SCHEMA' : 'RESULT_SCHEMA'}, не сообщение человеку.`

const resumePrompt = (item, dirtyPaths) => `Read-only реконструкция результата автора ${item.id}
для resume W2 по D155. ТЗ: ${PLAN}/phases/P4.md ${item.id}; dirty candidate paths:
${dirtyPaths.join('\n')}. Прочитай git diff и untracked files только в Files item'а, Gate B
${PLAN}/artifacts/P5-gate-b.md и research/16-p4-w2-dor-reconcile-2026-08.md. Убедись, что
candidate действительно меняет этот scope; выполни item-local проверки, которые не мутируют
дерево. Ничего не менять и не коммитить. status=ready только если можно честно восстановить
summary/files/covered_rules/load_bearing/failure_scenario и tests_green=true; общие joined-tree
gates перечисли в validation либо pending. Для P4.10 broad candidate допустим как реконструируемый
вход review, но НЕ как готовое evidence: построи rule_evidence по фактическим байтам и перечисли
пропуски в pending; structural green может оставить status=ready, чтобы review/fix нормализовал
candidate по research/17. Отсутствующий scope или неразрешимый факт → blocked.
Финальный текст = данные по ${item.id === 'P4.10' ? 'P410_RESULT_SCHEMA' : 'RESULT_SCHEMA'}, не сообщение человеку.`

const p410ShardPrompt = (shard, mode, dirtyPaths) => `${mode === 'fresh' ? 'Исполни authoring' : 'Read-only реконструируй candidate'}
P4.10 shard ${shard.id}: ${shard.subject}. Сначала прочитай ${PLAN}/phases/P4.md P4.10,
${PLAN}/research/17-p4.10-behavior-carrier-dor-2026-08.md целиком и соответствующие строки
${PLAN}/artifacts/P5-gate-b.md. ${mode === 'fresh' ? `Пиши только ${shard.files}.` : `Ничего не
меняй. Dirty candidate paths: ${dirtyPaths.join('\n')}. Анализируй только ${shard.files}.`}
Каждая behavior pair несёт ровно один rule id, формальный stimulus и адресный oracle; верни
rule_evidence только для exact rule-set этого shard. SEC-17/AOB-26 запрещены. Только shard dialog
возвращает retained_carriers для longop-002/102; остальные возвращают пустой массив.
${mode === 'resume' ? `Broad candidate разрешён только как вход review: отрази semantic gaps в
pending, но structural-green реконструкция может иметь status=ready для последующей fix-итерации.` : ''}
Выполни item-local read-only проверки; общие joined-tree gates оставь в pending. Git и plan files
не трогай. Финальный текст = данные по P410_RESULT_SCHEMA для этого shard, не сообщение человеку.`

const reviewPrompt = (item, result) => `Read-only adversarial review ${item.id}. ТЗ:
${PLAN}/phases/P4.md; находки round 1: ${PLAN}/artifacts/P5-gate-b.md; изменённые файлы:
${result.files.join(', ')}. Проверь: stimulus выводим из given/script; error/diagnostic адресен;
каждый spec_rule_id реально различается; negative имеет positive pair; правильная реализация не
падает на кейсе, предназначенном для accept. Для P4.10 обязательно прочитай research/17:
rule_evidence = exact 65 ids, каждая fixture pair несёт ровно один id, JSON Pointers разрешимы,
SEC-17/AOB-26 отсутствуют, longop-002/102 отдельно retained; broad pair = Major. Для P4.12
проверь static pairs SEC-17/AOB-26 по research/17 §4. Ничего не менять. Major/Blocker перечисли точно;
verdict=FIX_REQUIRED при любом Major/Blocker. Финальный текст = данные по REVIEW_SCHEMA.`

const fixPrompt = (item, findings) => `Исправь только подтверждённые Major/Blocker ${item.id}:
${findings.map((f, n) => `${n + 1}. ${f.where}: ${f.why}; fix=${f.fix}`).join('\n')}
ТЗ и запреты — ${PLAN}/phases/P4.md ${item.id}. Пиши только Files item'а, git/план не трогай.
Перепрогони item-local проверки и верни ${item.id === 'P4.10' ? 'P410_RESULT_SCHEMA' : 'RESULT_SCHEMA'} со status=ready. Финальный текст = данные
по соответствующей schema, не сообщение человеку.`

const p410FixPrompt = (shard, findings) => `Исправь P4.10 shard ${shard.id}: ${shard.subject}.
Все review findings (применяй только относящиеся к своему shard):
${findings.map((f, n) => `${n + 1}. ${f.where}: ${f.why}; fix=${f.fix}`).join('\n')}
Прочитай ${PLAN}/phases/P4.md P4.10 и
${PLAN}/research/17-p4.10-behavior-carrier-dor-2026-08.md. Пиши только ${shard.files}; чужие
shards, git и plan files не трогай. Нормализуй exact single-rule pairs и верни rule_evidence
только своего shard. SEC-17/AOB-26 запрещены; retained_carriers непустой только для dialog.
Перепрогони item-local проверки. Финальный текст = P410_RESULT_SCHEMA, status=ready.`

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
данные по CLOSE_SCHEMA, status=blocked обязан назвать concrete failure_scenario.
Для P4.10 close дополнительно требует exact rule_evidence 65/65 + retained longop и запрещает
SEC-17/AOB-26; для P4.12 требует их две static pairs по D156.`

async function safeAgent(prompt, opts) {
  try { return await agent(prompt, opts) }
  catch (e) { log(`agent error (${opts.label || '?'}): ${e.message || e}`); return null }
}

phase('Precheck')
const pre = await safeAgent(precheckPrompt, { label: 'precheck:W2', model: ROUTE.model, effort: 'low', schema: PRECHECK_SCHEMA })
if (!pre?.ok) return { stopped: true, reasons: pre?.reasons || ['precheck failed'] }

phase('Author')
const p410Item = WAVE.find(item => item.id === 'P4.10')
const authorTasks = [
  ...P410_SHARDS.map(shard => () =>
    agent(p410ShardPrompt(shard, pre.mode, pre.dirty_paths), {
      label: `${pre.mode === 'fresh' ? 'author' : 'resume'}:P4.10:${shard.id}`,
      model: ROUTE.model, effort: ROUTE.effort, schema: P410_RESULT_SCHEMA,
    }).then(result => ({ kind: 'p410', shard, result }))
  ),
  ...WAVE.filter(item => item.id !== 'P4.10').map(item => () =>
    agent(pre.mode === 'fresh' ? authorPrompt(item) : resumePrompt(item, pre.dirty_paths), {
      label: `${pre.mode === 'fresh' ? 'author' : 'resume'}:${item.id}`,
      model: ROUTE.model, effort: ROUTE.effort, schema: resultSchemaFor(item),
    }).then(result => ({ kind: 'item', item, result }))
  ),
]
const authorParts = await parallel(authorTasks)
const authored = [
  {
    item: p410Item,
    result: aggregateP410(authorParts.filter(part => part?.kind === 'p410')),
  },
  ...authorParts.filter(part => part?.kind === 'item').map(part => ({ item: part.item, result: part.result })),
]

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
    if (entry.item.id === 'P4.10') {
      const fixParts = await parallel(P410_SHARDS.map(shard => () =>
        agent(p410FixPrompt(shard, serious), {
          label: `fix:P4.10:${shard.id}#${iteration + 1}`,
          model: ROUTE.model, effort: ROUTE.effort, schema: P410_RESULT_SCHEMA,
        }).then(shardResult => ({ shard, result: shardResult }))
      ))
      result = aggregateP410(fixParts)
    } else {
      result = await safeAgent(fixPrompt(entry.item, serious), {
        label: `fix:${entry.item.id}#${iteration + 1}`, model: ROUTE.model,
        effort: ROUTE.effort, schema: resultSchemaFor(entry.item),
      })
    }
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
