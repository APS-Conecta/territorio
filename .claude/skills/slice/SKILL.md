---
name: slice
description: Build one Territorio issue end to end — pin the ticket, /implement (which runs /tdd then /code-review), the four suites, the browser gate, then land it. Use when the user says "slice 19", "/slice <issue>", "do ticket 9", or asks to work through the open issues.
---

# One slice, end to end

Each Territorio issue is built as one slice and lands the same way. This is that
way, in order. `/slice <issue-number>`.

Nothing here is ceremony: every step exists because skipping it cost this project
something specific, and the step says which.

## 1. Pin

```bash
gh issue view <n> --comments
```

Read the acceptance criteria as the spec — they are the contract, and the closing
comment will be judged against them one by one.

Read `CONTEXT.md` and any ADR the issue touches. Use the glossary's words, not the
synonyms it tells you to avoid: a Sector is not a zone, a Limit is not a border.
If your design contradicts an ADR, say so out loud rather than quietly overriding
it — `_Contradicts ADR-0007 (…) — but worth reopening because…_`.

Then branch off `main`:

```bash
git checkout main && git pull && git checkout -b feat/<n>-<slug>
```

## 2. Outline

Before writing anything, write down — briefly — which files the slice touches and
which acceptance criteria are **mechanically testable** versus **only visible in a
browser**. That split decides where the proof goes, and it is the sentence the
closing comment needs anyway.

## 3. Implement

Run **`/implement`**, handing it the acceptance criteria as the spec. It chains
the rest of the build for you:

- it uses **`/tdd`** at the seams — the failing test first, then the code, and the
  test must actually fail first or it is proving nothing;
- when it is done it runs **`/code-review main`**, the two-axis review (Standards
  against the repo's own conventions and Fowler's smells; Spec against the
  issue's criteria), as parallel subagents.

Write the least code that works, in this order — stop at the first rung that
holds:

1. Does it need to exist at all? Speculative need, skip it and say so.
2. Already in this repo? Reuse it. Look before writing.
3. Standard library.
4. Native platform feature — `<input type="color">` over a picker component, a Leaflet pane over a z-index, a DB constraint over app code.
5. A dependency already installed.
6. One line.
7. Only then, the minimum that works.

DRY, KISS, YAGNI, SOLID. No abstraction with one implementation, no config for a
value that never changes, no scaffolding for later. Deletion over addition.

Mark a deliberate shortcut with its ceiling so it can be found again:

```php
// ponytail: unpaginated; paginate when a real catastro makes this slow.
```

**Comments say why, not what**, and cite the ADR or the glossary line they come
from. A comment that will be false next slice is worse than none — this repo
shipped one saying Zones "arrive with issue #10" while being issue #10.

## 4. The suites

```bash
make test            # PHP unit — bare container, no Nextcloud
make test-js         # vitest over the pure frontend modules
make lint            # php -l over lib/, tests/, tools/
make lint-js         # eslint: nothing used but never imported
make instance-up
docker compose -f compose.dev.yaml exec --user www-data nextcloud php occ upgrade
make test-integration
```

`occ upgrade` is not optional when the slice adds a migration: the integration
suite runs against the instance's real schema, and without it you are testing
last week's.

## 5. The browser gate

Nothing enforces it. A private repo on this plan cannot have a protected branch or
a ruleset, and there is one account, so no check anywhere can refuse a merge. Treat
it as the step you do not skip, and say in the closing comment whether you ran it.

```bash
make build
# bump <version> in appinfo/info.xml
docker compose -f compose.dev.yaml exec --user www-data nextcloud php occ upgrade
make test-browser
```

**Bump the version.** Nextcloud's `?v=` on the script URL comes from
`appinfo/info.xml`, not from the file's contents, so a rebuilt bundle is served
from cache and you debug a fix that never reached the browser.

Then extend `tools/browser-check.py` with the criteria this slice added, and run
it. Assert on the **Registry**, not the screen: a green screen over an empty table
is precisely the failure this gate exists to catch.

Where a criterion asks for human judgement — "reviewed against a real sector" —
drive it in the browser, screenshot it, look at the screenshot, and report what
you actually saw. Then say it was reviewed by an agent, not a person.

Credentials for the throwaway dev instance: `http://localhost:8083`, `admin` /
`dev`. Loopback only, invented data, safe to break.

If Claude in Chrome misbehaves — tabs vanishing, screenshots timing out — do not
grind on it. Playwright via `tools/browser-check.py` is the reliable path and the
one the gate is built on.

## 6. Fix what the review found

Both axes report before anything merges. Fix what is real. Where a finding is
wrong, say so in writing with the reason — pushing back has been right several
times here. The review has found a genuine defect in every slice so far, twice
something that would have destroyed data.

Re-run the suites and the browser gate after fixing.

## 7. Land

- **CHANGELOG.md** — an entry per slice: what changed, and the *why* behind anything surprising.
- **An ADR** only for a real decision with consequences. Not for a summary of the work.
- **No other docs.** The issue and the CHANGELOG are the record.

```bash
git push -u origin feat/<n>-<slug>
gh pr create --fill                     # fills the checklist from .github/PULL_REQUEST_TEMPLATE.md
gh pr checks --watch                    # the docs workflow — red for now, see CLAUDE.md
gh pr merge --rebase --delete-branch    # keeps main linear, as it has always been
gh issue close <n> --comment "…"
```

Through a pull request, never `git push origin main`. The org works GitHub Flow and
one approval is the convention — but GitHub will not let anybody approve their own
pull request, and no ruleset can require it here, so the approval is you reading
your own diff on the PR page before merging. It is a checkpoint, not a gate. Say so
plainly rather than describing a review that no second person performs.

The closing comment separates three things, plainly:

- **verified mechanically** — which criteria, by which test;
- **verified in a browser** — which criteria, and what was seen;
- **not verified** — say it. A criterion nothing exercised is not met, however good the code looks.

## Two rules this project learned the hard way

**Prove the check.** After writing a guard, reintroduce the bug and watch it fail.
A guard nobody has seen fail is a guard nobody knows works.

**Say what is unverified.** `Guardar` did nothing for eight slices while three
suites, a webpack build and a two-axis review passed on every one of them. The
tests were green and the claim was wrong. Never let the shape of the process
stand in for evidence.
