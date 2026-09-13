# Territorio

## Building an issue

`/slice <issue-number>` — the whole loop, in order: pin the ticket, `/implement`
(which runs `/tdd`, then `/code-review`), the four suites, the browser gate, then
land. See `.claude/skills/slice/SKILL.md`.

Nothing in that loop blocks a merge, and no wording here should pretend otherwise:
this is a private repo on a plan with no branch protection and one account, so the
browser gate is a step you do not skip rather than a gate that stops you. It exists
because `Guardar` did nothing for eight slices while every other check passed.
`.claude/skills/slice/SKILL.md` § 7 has the landing sequence.

The one mechanical check is the `docs` workflow, and **it passes**:
`python3 .github/repo-docs.py check . --offline` exits 0 with 0 errors (measured
2026-09-13). Both `profiles/aps-conecta.json` and `profiles/_base.json` — which holds
the `levels` key — are vendored at `.github/profiles/`.

This paragraph previously said the gate *"cannot pass today"* because the checker was
vendored without its `profiles/`, exiting on `KeyError: 'levels'`. That was true when
written and was fixed in `repo-docs` without this note being updated, so the repository
spent an unknown stretch telling every agent its only mechanical check was broken — which
is a reliable way to get a working gate skipped. Both profile files are canon and are
overwritten by `--fix`, so they are still not edited here.

## Agent skills

### Issue tracker

Issues and PRDs live as GitHub issues, managed with the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

The five canonical triage roles, each label string equal to its name. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: one `CONTEXT.md` and `docs/adr/` at the repo root. See `docs/agents/domain.md`.
