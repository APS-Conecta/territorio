# Documentation index — Territorio

Where each document lives and which facts it is the authority for. When two documents seem to state
the same fact, the one named here as its authority wins and the other should link instead.

## Start here

| Document | It is the authority for |
|---|---|
| [`README.md`](../README.md) | What this app is, its status, how to run it, install it and configure it |
| [`CONTEXT.md`](../CONTEXT.md) | **The vocabulary.** Comuna, Territorio, Sector, Feature, Limit, Dataset, and the words to avoid |
| [`adr/`](adr/) | Decisions, and the reasoning that was live when each was taken |
| [`CLAUDE.md`](../CLAUDE.md) | Which agent skill runs what, and what is not enforced by anything |

The organisation's contributing doctrine and security policy are not here: they are **to be** served to every
repository from [`APS-Conecta/.github`](https://github.com/APS-Conecta/.github), and a local copy
would be a second owner of the same facts. Org-wide decisions — the licence, the trunk, the release
model — live in [`APS-Conecta/gestion`](https://github.com/APS-Conecta/gestion/tree/main/docs/adr).

> **Not live yet.** GitHub serves organisation defaults only from the default branch of
> `APS-Conecta/.github`, and both files are still on an unmerged branch there
> ([PR #1](https://github.com/APS-Conecta/.github/pull/1)). Until it merges this repository has
> no contributing or security document from any source. The docs gate reads the local clone, so
> it already counts them as present — that is deliberate, to keep the check offline, and it is
> why these close on **merge**, not on commit.

## Explanation — why it looks the way it does

| Document | It is the authority for |
|---|---|
| [`design/map-legibility.md`](design/map-legibility.md) | The mark system: hue, glyph, size, and the contrast the ramp must hold. `tools/ramp-check.py` enforces its arithmetic |

## For an agent working here

| Document | It is the authority for |
|---|---|
| [`../.claude/skills/slice/SKILL.md`](../.claude/skills/slice/SKILL.md) | How one issue is built and landed, end to end |
| [`agents/issue-tracker.md`](agents/issue-tracker.md) | That issues and PRDs are GitHub issues, and the `gh` commands for them |
| [`agents/triage-labels.md`](agents/triage-labels.md) | The five triage roles and this tracker's label strings |
| [`agents/domain.md`](agents/domain.md) | Which domain docs to read before exploring the code |

## Status and history

| Document | It is the authority for |
|---|---|
| [`../CHANGELOG.md`](../CHANGELOG.md) | What changed, slice by slice |
| [`../THIRD-PARTY-NOTICES.md`](../THIRD-PARTY-NOTICES.md) | Every third-party component in the shipped bundles, and its licence |
| [`../LICENSE`](../LICENSE) | AGPL-3.0-or-later, the whole text |
| GitHub issues | What is left. The README's status section is a snapshot; the issues are the record |

## A note on the ADR numbers

Numbers are never reused and never renumbered. `0001` is the oldest decision here and one of its
premises turned out to be wrong rather than superseded: it claimed a REST surface that was never
built, and [`0014`](adr/0014-the-cross-app-surface-is-injection-not-rest.md) records what replaced
it, with `0001` linking forward from its Status.

Dated records — the ADRs and the CHANGELOG — are not rewritten when the world moves. A correction
is added with its own date, which is why [`0004`](adr/0004-openstreetmap-is-tiles-and-offline-imports-only.md)
still names the Dataset slug it was written with.
