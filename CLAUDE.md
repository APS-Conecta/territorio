# Territorio

## Building an issue

`/slice <issue-number>` — the whole loop, in order: pin the ticket, `/implement`
(which runs `/tdd`, then `/code-review`), the four suites, the browser gate, then
land. See `.claude/skills/slice/SKILL.md`.

The browser gate blocks the merge. It exists because `Guardar` did nothing for
eight slices while every other check passed.

## Agent skills

### Issue tracker

Issues and PRDs live as GitHub issues, managed with the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

The five canonical triage roles, each label string equal to its name. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: one `CONTEXT.md` and `docs/adr/` at the repo root. See `docs/agents/domain.md`.
