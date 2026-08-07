# Territorio

The territory a CESFAM serves, on a map: the official Unidades Vecinales, the sectores the clinic
draws for itself, and the places in the comuna — colegios, plazas, farmacias, organizaciones
sociales. A Nextcloud 34 app.

Contains no patient information.

- **Vocabulary** — [`CONTEXT.md`](CONTEXT.md). Use these words; they are chosen, not incidental.
- **Decisions** — [`docs/adr/`](docs/adr/). Read the ones touching what you are about to change.

## Current state

Issues #1–#7, #10 and #12: the app shell and the map; Places created, edited, retired and
restored, with history and a polled change cursor behind them; the Capas panel driving map, Datos
and Análisis from one filtered set; GeoJSON import as a Dataset, revertible batch by batch; and
Sectores drawn by hand, named, coloured and reshaped.

Open issues carry what is left.

## Working on it

    make test             # PHP unit tests in a bare container — no Nextcloud needed
    make test-js          # the frontend's pure logic (filters, counts) — vitest
    make lint             # php -l over lib/, tests/ and tools/
    make lint-js          # eslint over src/ and tests/js — one rule: nothing undefined
    make test-browser     # drive the running app with Playwright (needs instance-up + build)
    npm install           # once
    make build            # webpack → js/territorio-{main,admin}.js
    make instance-up      # throwaway Nextcloud 34 + PG 18 on :8083 (admin / dev)
    make test-integration # mapper round-trip against that instance's real Postgres
    make instance-clean   # wipe it

Four suites, on purpose:

- **`make test`** is the judgement — validation, geometry, status codes. It depends on no OCP
  class and runs in a bare PHP container in milliseconds. Classes that must touch Nextcloud are
  tested against the `nextcloud/ocp` interface stubs, mapped in `autoload-dev` only.
- **`make test-js`** is the frontend's judgement — the filter reducer, the counts. Pure modules,
  no browser, no Nextcloud.
- **`make test-integration`** is the round trip. A mocked mapper cannot tell you whether an
  Entity's camelCase properties actually reach the snake_case columns the migration created, with
  the right types — only a real insert can, and a wrong answer there is invisible until data is
  lost. Needs `make instance-up` first.
- **`make test-browser`** is the app. It is the only thing here that opens it, and it exists because
  everything above passed for eight slices while **`Guardar` did nothing at all** — `native-type`
  is the @nextcloud/vue 8 prop name, so under 9 the button stayed a `type="button"` and never
  submitted its form. No unit test loads a component and webpack emits whatever it is given, so the
  database was the only witness: every revision carried a null actor, meaning `occ` or a test, never
  a person. It asserts on the Registry rather than the screen — a green screen over an empty table
  is the failure it is here to catch. Needs `make instance-up` and a current `make build`.

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

## Boundary features

The lines a Sector's Limit can run along — the comuna's named streets, canals and railways — come
from OpenStreetMap through a **repo-side** tool, never from a call the app makes
([ADR-0004](docs/adr/0004-openstreetmap-is-tiles-and-offline-imports-only.md)):

    php tools/boundary-features.php --bbox=<sur,oeste,norte,este> --source=<slug> > limites.geojson
    occ territorio:import limites.geojson --dataset=<slug> --label="Límites" --boundary

`--boundary` is what keeps them off the map. They are Features like any other and go through the
same import, batch and revert, but a comuna is around eleven thousand of them: the flag marks the
Dataset, and every ordinary read — the map, the Datos list, the counts, the change feed — leaves
the whole collection out. They are loaded only while a Sector's boundary is being built.

Refreshing them later is just re-running both commands. Ids come from OSM, so a street redrawn
upstream updates the record it already has, and no Sector already drawn is moved.

## The basemap

Tiles come from OpenStreetMap by default. An administrator can repoint them at any tile server
(Settings → Administration → Territorio), including a self-hosted one for a clinic without
internet access — see [ADR-0004](docs/adr/0004-openstreetmap-is-tiles-and-offline-imports-only.md).

The Content-Security-Policy host is derived from whatever URL is configured, so the two can never
drift apart. A URL that is not a usable tile template is stored as typed but never served: the map
falls back to OpenStreetMap and the settings page says so.
