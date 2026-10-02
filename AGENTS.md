# AGENTS.md — Territorio

Full project guidance — skills, the review loop, CI and the docs gate — lives in [`CLAUDE.md`](CLAUDE.md); read it first.

## Scheduled Jules agents

One Jules agent visits this repo each night at 02:00 (Chile): Mon 🗡️ Breaker, Tue 🐞 Tracer,
Wed ⚡ Bolt / ✂️ Simplifier (alternate weeks), Thu 🧹 Janitor, Fri 🎨 Palette, Sat 📚 Scribe,
Sun 🔤 Lexicon. Their prompts and the code standards live in the private `APS-Conecta/agents` repo;
Claude reviews every PR. The rules below are this repo's own facts, and they bind every agent.

- **Journals** live only in lowercase `.jules/` (`breaker.md`, `tracer.md`, `bolt.md`, `simplifier.md`,
  `janitor.md`, `palette.md`, `scribe.md`, `lexicon.md`) — never create `.Jules/`.
  Date each entry with today's `date +%F`, never a remembered or copied date.
- **Names:** code is English, but a domain term keeps the spelling `CONTEXT.md` gives it (`saveComuna` and
  `unidadVecinalId`, not `saveMunicipality` or `neighbourhoodUnitId`). Rename an existing name only inside
  code a change already touches, and never a table, column, route, controller action (an OpenAPI operation
  id) or API field.
- **Threat model:** read `docs/threat-model.md` before any security work.
- **Gate before opening a PR** — what `ci.yml` and `docs.yml` run. This repo uses **npm**, not pnpm:
  `git clone --depth 1 https://github.com/APS-Conecta/aps-common.git ../aps-common` if it is missing — aps-common is
  private, so if the clone fails, skip the steps that need it and list them as not run; never edit a workflow to work around it;
  `make aps-drift`; `npm ci`; `make lint-js`; `make test-js`; `make build`, then `git status --porcelain -- js/` prints
  nothing; `make notices`; then `make test` and `make lint` — after the npm steps, since `make test` copies a node:test
  file into `vendor/` that `make test-js` would then fail on; `python3 .github/repo-docs.py check . --offline`.
  A step you could not run is named in the PR, never reported as passed.
- **`src/` changed → `make build`** and commit the regenerated `js/` (bundles and their `.LICENSE.txt`) in the same PR.
  Never commit `*.js.map` or `node_modules/`.
- **Never edit** `.github/repo-docs.py`, `.github/profiles/*`, `package-lock.json`, `composer.lock`, the dependency lists
  in `package.json`/`composer.json`, `js/` by hand, `openapi.json` (generated from `ApiController`, ADR-0020 — the
  `openapi` CI job fails on drift), or the aps-common copies `src/aps/`, `vendor/aps/common/src/`, `tests/stubs/OC/Hooks/Emitter.php`.
- **Strings:** UI text is Spanish, written as literals the way the surrounding components do — `src/` has no
  `@nextcloud/l10n` `t()`, so do not add one; PHP uses `$this->l->t('…')` where `IL10N` is already injected.
  Code, comments, commits and PR text English.
- **One concern per PR**, under 50 lines of hand-written source (rebuilt bundle excluded), and it contains every change
  its description claims — a journal-only PR is invalid. Nothing worthwhile → no PR. Never repeat a change an open or
  recently closed PR already proposes.
- **PR text:** keep the title prefix and body format your agent's prompt gives (emoji and name as above);
  the maintainer gives the squash commit its Conventional Commits title and adds the `ai-assisted` label.
