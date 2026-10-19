# Territorio

Cross-repo ADR citations are `repo#ADR-NNNN` (`territorio#ADR-0016`); in-repo citations stay bare. The org-wide contracts index lives in `gestion/docs/CONTRACTS.md`.

## Building an issue

`/slice <issue-number>` — the whole loop, in order: pin the ticket, `/tdd`, then
`/review main` (the two-axis Standards + Spec review), the four suites, the browser
gate, then land. See `.claude/skills/slice/SKILL.md`.

`/sprint <épica>` runs a whole epic that way, one issue at a time, in the order the
issues' own `## Blocked by` and `## Decided` sections dictate. See
`.claude/skills/sprint/SKILL.md`.

`/review main` is the two-axis review; `/code-review` is not.

Nothing in that loop blocks a merge, and no wording here should pretend otherwise:
this is a private repo on a plan with no branch protection and one account, so the
browser gate is a step you do not skip rather than a gate that stops you. It exists
because `Guardar` did nothing for eight slices while every other check passed.
`.claude/skills/slice/SKILL.md` § 7 has the landing sequence.

CI runs the PHP tests and lint, the JS lint and tests, and a rebuild that fails the
PR if committed `js/` has drifted from `src/` (`.github/workflows/ci.yml`). The
integration and browser suites stay manual, on purpose: they need a whole instance.

The other mechanical check is the `docs` workflow, and **it passes**:
`python3 .github/repo-docs.py check . --offline` exits 0 with 0 errors (measured
2026-09-13). Both `profiles/aps-conecta.json` and `profiles/_base.json` — which holds
the `levels` key — are vendored at `.github/profiles/`. Both are canon and are
overwritten by `--fix`, so they are not edited here.

## Agent skills

### Issue tracker

Issues and PRDs live as GitHub issues, managed with the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

The five canonical triage roles, each label string equal to its name. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: one `CONTEXT.md` and `docs/adr/` at the repo root. See `docs/agents/domain.md`.
