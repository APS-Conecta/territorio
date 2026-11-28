# Territorio

Cross-repo ADR citations are `repo#ADR-NNNN` (`territorio#ADR-0016`); in-repo citations stay bare. The org-wide decisions live as ADRs in the suite repository (`APS-Conecta/instalador`, `docs/adr/`); cross-app contracts are each app's OpenAPI surface, indexed on the documentation site.

## Working rules

- **Standards:** the org's code rules are the docs site's [«Estándares de código»](https://aps-conecta.github.io/documentation/desarrollo/estandares-de-codigo.html) page.
- **Seams:** server behaviour is tested in the integration suite (`tests/integration/`) against the
  throwaway instance's real PostgreSQL; UI behaviour in the browser suite (`tools/browser-check.py`)
  against the same instance. `.claude/skills/slice/SKILL.md` §4-5 says how to run both. A PR names the
  seam its test lives at; a test at any other seam is a review finding.

## Building an issue

`/slice <issue-number>` — the whole loop, in order: pin the ticket, `/tdd`, then
`/review main` (the two-axis Standards + Spec review), the four suites, the browser
gate, then land. See `.claude/skills/slice/SKILL.md`.

`/sprint <épica>` runs a whole epic that way, one issue at a time, in the order the
issues' own `## Blocked by` and `## Decided` sections dictate. See
`.claude/skills/sprint/SKILL.md`.

`/review main` is the two-axis review; `/code-review` is not.

The repo is public, and main gets a ruleset (APS-Conecta/.github#16) that requires a pull
request and CI's checks, so a red or pending check refuses the merge. The browser gate is one of
those checks. It exists because `Guardar` did nothing for eight slices while every other check
passed. `.claude/skills/slice/SKILL.md` § 7 has the landing sequence.

CI runs on every pull request, with no path filter (`.github/workflows/ci.yml`): the PHP tests
and lint, the JS lint and tests, the org's shared style check (the `style` job, pinned by commit
SHA: no literal colour, no font size in px, no plain `<button>`), and one instance job that brings up the throwaway instance and
runs the OpenAPI no-drift check, the integration suite and the browser gate on it. The release
workflow builds and packs the bundle on every pull request too (`.github/workflows/release.yml`).

## Agent skills

### Issue tracker

Issues and PRDs live as GitHub issues, managed with the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

The five canonical triage roles, each label string equal to its name. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: one `CONTEXT.md` and `docs/adr/` at the repo root. See `docs/agents/domain.md`.
