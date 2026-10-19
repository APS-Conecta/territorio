export const meta = {
  name: 'decidir',
  description: '3-agent decision protocol for one Territorio issue: proponente vs opositor, decididor rules IN/OUT/MODIFIED',
  whenToUse: 'Before implementing a slice the plan marks as needing a design decision. Pass args {issue, propuesta, contexto?}.',
  phases: [
    { title: 'Proponer', detail: 'proponente argues for the simplest solution that works' },
    { title: 'Oponer', detail: 'opositor attacks: over-engineering, edge cases, violated ADRs' },
    { title: 'Decidir', detail: 'decididor reads both and rules; simpler wins ties' },
  ],
}

// Everything the debate is about arrives through `args`. Nothing else reaches a
// workflow script, so a version without this line debated nothing in particular.
if (!args || !args.issue || !args.propuesta) {
  throw new Error('decidir needs args {issue: <n or slug>, propuesta: <one paragraph>, contexto?: <measured facts>}')
}

const ISSUE = String(args.issue)
const PROPUESTA = String(args.propuesta)
const CONTEXTO = args.contexto ? String(args.contexto) : '(ninguno)'

// The ground every role stands on. The ladder is quoted rather than referenced so
// the three agents argue about the same seven rungs.
const SUELO = `Repo: the current working directory, the checkout this workflow was started in (Nextcloud app "territorio"). Issue: ${ISSUE} — read it first:
  gh api repos/APS-Conecta/territorio/issues/${ISSUE} --jq .body  (if numeric; otherwise the issue text is in "propuesta")
  and its comments: gh api repos/APS-Conecta/territorio/issues/${ISSUE}/comments --jq '.[].body'
Vocabulary: CONTEXT.md. Decisions: docs/adr/ — both relative to the repo root (an issue that an ADR already decides is not debated — it is followed).
Measured facts from the orchestrator (trust these over the plan): ${CONTEXTO}

La escalera ponytail — parar en el primer peldaño que aguante:
  1 ¿Necesita existir? (YAGNI)  2 ¿Ya está en el codebase? (grep antes de opinar)  3 ¿Stdlib?
  4 ¿Plataforma nativa? (CSS sobre JS, constraint de BD sobre código, <input> nativo sobre componente)
  5 ¿Dependencia ya instalada?  6 ¿Una línea?  7 Solo entonces: el mínimo que funciona.
Every argument cites a file:line you opened, an ADR, or an acceptance criterion. Opinions from training data do not count.
Never simplify away: validation at trust boundaries, error handling that prevents data loss, security, accessibility basics, anything the issue's acceptance criteria require.`

phase('Proponer')
const pro = await agent(
  `${SUELO}

Eres el PROPONENTE. Defiendes la solución propuesta — pero eres ponytail: defiendes la más simple que cumple los criterios de aceptación, no la más elegante. Si la propuesta original está sobre-construida, dilo y defiende la versión simplificada.

PROPUESTA: ${PROPUESTA}

Responde en este formato, en español, ≤ 60 líneas:
### Solución propuesta
[una frase; la simplificada si la original era over-engineered]
### Peldaño
[1-7 y por qué]
### Argumentos a favor
1. **título** — evidencia archivo:línea / ADR / criterio
2. ...
### Qué se deja fuera (y cuándo agregarlo)
### Objeciones respondidas
- "objeción previsible" → por qué no aplica o cómo se mitiga`,
  { label: 'proponente', phase: 'Proponer', effort: 'high' },
)

phase('Oponer')
const contra = await agent(
  `${SUELO}

Eres el OPOSITOR, abogado del diablo ponytail. Atacas por dos flancos: (1) hay un peldaño MÁS ALTO que el proponente no tomó; (2) la solución está rota — edge cases (10.000 Features, nombre vacío, geometría nula, upgrade de una instalación existente), ADRs contradichos (BLOCKER), performance, datos huérfanos, acoplamiento. Cada objeción es concreta: "si pasa X, Y falla porque Z", con archivo:línea.

PROPUESTA ORIGINAL: ${PROPUESTA}

LO QUE DIJO EL PROPONENTE:
${pro}

Responde en español, ≤ 60 líneas:
### Objeciones
1. **título** — mecanismo de fallo concreto · Severidad: BLOCKER | MAJOR | MINOR · Archivos: ...
### ¿Hay un peldaño más alto?
[Sí/No — cuál y por qué, con evidencia]
### Alternativa más simple
[≤ 3 líneas, o "Nada más simple cumple los criterios de aceptación."]`,
  { label: 'opositor', phase: 'Oponer', effort: 'high' },
)

const FALLO = {
  type: 'object',
  properties: {
    fallo: { type: 'string', enum: ['IN', 'OUT', 'MODIFIED'] },
    razon: { type: 'string', description: 'One sentence: the deciding factor, with the file:line or ADR that tipped it.' },
    peldano: { type: 'integer', minimum: 1, maximum: 7, description: 'Ladder rung the ruled solution lands on.' },
    solucion: { type: 'string', description: 'The ruled solution in ≤ 5 lines — what gets built. This is what the implementer executes.' },
    cambios: { type: 'array', items: { type: 'string' }, description: 'If MODIFIED: each concrete change vs the proposal, with file path.' },
    descartado: { type: 'array', items: { type: 'string' }, description: 'What is explicitly NOT built — as important as what is.' },
    adrs_a_actualizar: { type: 'array', items: { type: 'string' }, description: 'ADRs this contradicts. Per docs/index.md an ADR is never rewritten: a dated correction/supersession is appended, or a new ADR is written, in the same PR.' },
    archivos: { type: 'array', items: { type: 'string' }, description: 'Files the ruled solution touches.' },
    pruebas: { type: 'array', items: { type: 'string' }, description: 'The failing-first tests the slice must write, one line each, naming the test file.' },
  },
  // Only what a ruling cannot be without. The first version required all seven, and a
  // decididor holding 85k tokens of argument answered with an empty object and burned its
  // retries against the validator — a schema that is hard to satisfy fails as "no ruling",
  // which is indistinguishable from "the protocol did not run".
  required: ['fallo', 'razon', 'solucion'],
}

phase('Decidir')
const fallo = await agent(
  `${SUELO}

Eres el DECIDIDOR. Lees los dos argumentos y emites un fallo definitivo. No reabres el debate; evalúas lo dicho, verificando en el codebase cualquier cita que incline la balanza.
Reglas: IN exige que el proponente gane en argumentos Y en simplicidad. Un peldaño más alto que el proponente no refutó → MODIFIED. Una objeción BLOCKER sin responder → OUT o MODIFIED. Ante empate técnico gana el que propone MENOS código. Si el fallo contradice un ADR vigente, ordena la corrección fechada (nunca reescribir el ADR) en el mismo PR. Especifica qué se construye y qué se descarta.

PROPUESTA ORIGINAL: ${PROPUESTA}

PROPONENTE:
${pro}

OPOSITOR:
${contra}`,
  { label: 'decididor', phase: 'Decidir', schema: FALLO, effort: 'high' },
)

log(`fallo #${ISSUE}: ${fallo.fallo} — peldaño ${fallo.peldano} — ${fallo.razon}`)

return { issue: ISSUE, proponente: pro, opositor: contra, fallo }
