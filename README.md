# Territorio

The territory a CESFAM serves, on a map: the official Unidades Vecinales, the sectores the clinic
draws for itself, and the places in the comuna — colegios, plazas, farmacias, organizaciones
sociales. A Nextcloud 34 app.

Contains no patient information.

- **Vocabulary** — [`CONTEXT.md`](CONTEXT.md). Use these words; they are chosen, not incidental.
- **Decisions** — [`docs/adr/`](docs/adr/). Read the ones touching what you are about to change.

## Current state

Issues #1 and #2: the app shell, the map, and Places — create, list and edit one record at a time.
Kind is Place only; Sectores, Zones, Routes, import, history and live sync are issues #3 onward.

## Working on it

    make test             # PHP unit tests in a bare container — no Nextcloud needed
    make test-js          # the frontend's pure logic (filters, counts) — vitest
    make lint             # php -l over lib/ and tests/
    npm install           # once
    make build            # webpack → js/territorio-{main,admin}.js
    make instance-up      # throwaway Nextcloud 34 + PG 18 on :8083 (admin / dev)
    make test-integration # mapper round-trip against that instance's real Postgres
    make instance-clean   # wipe it

Three test suites, on purpose:

- **`make test`** is the judgement — validation, geometry, status codes. It depends on no OCP
  class and runs in a bare PHP container in milliseconds. Classes that must touch Nextcloud are
  tested against the `nextcloud/ocp` interface stubs, mapped in `autoload-dev` only.
- **`make test-js`** is the frontend's judgement — the filter reducer, the counts. Pure modules,
  no browser, no Nextcloud. UI *rendering* is still unverified by anything but a person looking at
  it; two of this project's bugs so far were visible only that way.
- **`make test-integration`** is the round trip. A mocked mapper cannot tell you whether an
  Entity's camelCase properties actually reach the snake_case columns the migration created, with
  the right types — only a real insert can, and a wrong answer there is invisible until data is
  lost. Needs `make instance-up` first.

### Two traps worth knowing

**APCu is per-process.** The dev instance uses APCu as its local cache, so changing a setting with
`occ config:app:set` is **not** visible to the web process until you
`docker compose -f compose.dev.yaml restart nextcloud`. The same applies to the route table after
adding a route — a new endpoint 404s until the restart. Changing a setting through the admin UI
has no such problem.

**`make build` does not bust the browser cache.** Nextcloud's `?v=` on a script URL comes from the
app version in `appinfo/info.xml`, not from the file's contents, so rebuilding the bundle leaves
the URL identical and the browser keeps serving the copy it already has. After `make build`,
**hard-reload** (Ctrl/Cmd+Shift+R). If you are handing the instance to someone else to look at,
bump the app version instead — that changes the URL for everyone.

## The basemap

Tiles come from OpenStreetMap by default. An administrator can repoint them at any tile server
(Settings → Administration → Territorio), including a self-hosted one for a clinic without
internet access — see [ADR-0004](docs/adr/0004-openstreetmap-is-tiles-and-offline-imports-only.md).

The Content-Security-Policy host is derived from whatever URL is configured, so the two can never
drift apart. A URL that is not a usable tile template is stored as typed but never served: the map
falls back to OpenStreetMap and the settings page says so.
