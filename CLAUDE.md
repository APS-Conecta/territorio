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

The one mechanical check is the `docs` workflow, and **it cannot pass today**:
`.github/repo-docs.py` is vendored without the `profiles/` it reads, so
`python3 .github/repo-docs.py check . --offline` exits on `KeyError: 'levels'`. Run
the gate from a checkout of [`APS-Conecta/repo-docs`](https://github.com/APS-Conecta/repo-docs)
until that is fixed there; both files are canon and are overwritten by `--fix`, so
the fix does not belong in this repo.

## Agent skills

### Issue tracker

Issues and PRDs live as GitHub issues, managed with the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

The five canonical triage roles, each label string equal to its name. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: one `CONTEXT.md` and `docs/adr/` at the repo root. See `docs/agents/domain.md`.
