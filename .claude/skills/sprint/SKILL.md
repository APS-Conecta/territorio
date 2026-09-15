---
name: sprint
description: Build one epic's issues end to end, in dependency order, one `/slice` at a time. Use when the user says "/sprint deuda", "/sprint legibilidad", "/sprint taxonomia", or asks to work through a whole epic of the Territorio backlog.
---

# One epic, in order

`/sprint <épica>` runs every issue of an epic as its own `/slice`, in the order below, stopping
the moment one fails. Nothing here overrides `.claude/skills/slice/SKILL.md` — that is the
contract for a single issue; this only says which issues, in which order, and what must not
happen between them.

## The epics

| `/sprint …` | Issues, in order |
|---|---|
| `deuda` | #60 → #26 → #28 → #87 |
| `integridad` | #83 → #51 → #50 |
| `legibilidad` | #37 → **#21+#24+#29** (one slice, one PR) → **#22+#23** (one slice) → #25 → #35 |
| `taxonomia` | #42 → #43 → #41 → #44 → #45 → #46 → #47 → close #36 |
| `io` | file the issues first (Apéndice A of the plan), then CSV import → KMZ export → KMZ import |
| `archivos` | file the issue first, then the Nextcloud Files reference |
| `indice` | file the issue first, then the table view |

**The order comes from the issues, not from this table.** Each issue carries `## Blocked by` and
sometimes `## Decided`; GitHub carries the same graph natively
(`gh api repos/APS-Conecta/territorio/issues/<n> --jq .issue_dependencies_summary`). This table is
a cache of that graph, and the graph wins whenever they disagree — re-derive it rather than
trusting the table. Two orderings in it exist only because an issue says so:

- **#21+#24+#29 are one slice and one PR** (`Closes #21, #24, #29`) — all three say so and so does
  ADR-0015. Splitting them leaves a Unidad Vecinal with no fill still offering a colour picker.
- **#22+#23 are one slice, and #37 lands before them** — #22 and #23 both say so: they rewrite the
  same function, and #37 re-tunes the pin geometry underneath both.

Epic order across the backlog: **deuda → integridad → legibilidad → taxonomia → io → archivos →
indice**. Taxonomy is last on purpose — #40 says it is "the last of the fourteen open issues to be
built", because it is the one that makes the taxonomy open-ended and invalidates the closed-ramp
premise every visual issue is written against.

## Running it

For each issue in order:

1. `gh issue view <n> --comments`. If a `## Blocked by` names an issue that is still open, **stop**
   and say which.
2. Run the full `/slice <n>` loop: pin → branch → TDD → `/review main` → the four suites → the
   browser gate → land.
3. If any suite or the gate fails and cannot be made green honestly, **stop**. Report the failing
   output. Do not continue to the next issue and do not weaken a test to pass.
4. Only then, the next issue.

Do not parallelise issues within an epic: they share one dev instance, and the order is the
dependency graph.

## The three agent protocol

Invoke it — `Workflow({scriptPath: '.claude/workflows/decidir.js', args: {issue, propuesta, contexto}})` —
only when **both** hold:

- the plan's section for that issue says **«3 agentes: Sí»**, and
- the issue has **no** `## Decided` section and no ADR that already dictates the solution.

Across the current backlog that is exactly one slice: **#51**, where the design question is
whether a re-import retires the rows a Dataset no longer publishes. Everything else is decided
already, in an ADR or in the issue, and re-debating a settled decision is how a plan ends up
building the wrong thing twice.

## Between slices

- **Leave the dev instance up.** Do not run `make instance-clean` or `make instance-down`: they
  are `docker compose down [-v]`, which needs the owner's confirmation (global rule 10), and `-v`
  wipes the seeded database the next slice's integration suite and browser gate both read.
- Each landed slice leaves `main` green: four suites, the browser gate, and CI on the PR.
- At the end of the epic, append one line to `/srv/sync/agent-locks/aps-conecta-org.lock`
  (global rule 12): `claude: PIVOT — épica <x> done, <n> PRs merged — <ISO timestamp>`.

## Reporting

At the end of the epic: issues closed, PRs merged, and — per issue — which acceptance criteria
were verified mechanically, which in a browser, and which were **not verified**. A criterion
nothing exercised is not met, however good the code looks.
