# Territorio

The territory a CESFAM serves, on a map: the official Unidades Vecinales, the sectores the clinic
draws for itself, and the places in the comuna — colegios, plazas, farmacias, organizaciones
sociales. A Nextcloud 34 app.

Contains no patient information.

- **Vocabulary** — [`CONTEXT.md`](CONTEXT.md). Use these words; they are chosen, not incidental.
- **Decisions** — [`docs/adr/`](docs/adr/). Read the ones touching what you are about to change.

## Current state

Issue #1 only: the app shell and the map. There is no territorial data yet — no Features, no
Sectores, no import. Those are issues #2 onward.

## Working on it

    make test           # PHP unit tests in a bare container — no Nextcloud needed
    make lint           # php -l over lib/ and tests/
    npm install         # once
    make build          # webpack → js/territorio-{main,admin}.js
    make instance-up    # throwaway Nextcloud 34 + PG 18 on :8083 (admin / dev)
    make instance-clean # wipe it

The tested core deliberately depends on no OCP class, so `make test` needs no Nextcloud. Classes
that must touch Nextcloud are tested against the `nextcloud/ocp` interface stubs, mapped in
`autoload-dev` only.

### A trap worth knowing

The dev instance uses APCu as its local cache, and APCu is per-process. Changing a setting with
`occ config:app:set` will **not** be visible to the web process until you
`docker compose -f compose.dev.yaml restart nextcloud`. This costs an hour if you do not know it.
Changing the setting through the admin UI has no such problem.

## The basemap

Tiles come from OpenStreetMap by default. An administrator can repoint them at any tile server
(Settings → Administration → Territorio), including a self-hosted one for a clinic without
internet access — see [ADR-0004](docs/adr/0004-openstreetmap-is-tiles-and-offline-imports-only.md).

The Content-Security-Policy host is derived from whatever URL is configured, so the two can never
drift apart. A URL that is not a usable tile template is stored as typed but never served: the map
falls back to OpenStreetMap and the settings page says so.
