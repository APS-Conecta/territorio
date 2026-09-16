# Territorio

## What this is

The territory a CESFAM serves, on a map: the official Unidades Vecinales, the sectores the clinic
draws for itself, and the places in the comuna — colegios, plazas, farmacias, organizaciones
sociales. A Nextcloud 34 app.

What it is not: a personal map. Nextcloud's own `maps` app models each user's favourites, tracks
and geolocated photos; this is one shared institutional registry that everyone edits, with
provenance and history behind every record
([ADR-0001](docs/adr/0001-standalone-app-not-nextcloud-maps.md)). It is also not multi-tenant —
one install serves one comuna and the territory of one CESFAM inside it, and which comuna that is
is configuration, not code.

Contains no patient information.

- **Vocabulary** — [`CONTEXT.md`](CONTEXT.md). Use these words; they are chosen, not incidental.
- **Decisions** — [`docs/adr/`](docs/adr/). Read the ones touching what you are about to change.

## Status — 2026-08-08

Version 0.27.0. `appinfo/info.xml` is where the version lives; nothing else declares one.

Working: the app shell and the map; Places created, edited, retired and restored, with history and
a polled change cursor behind them; the Capas panel and text search driving map, Datos and Análisis
from one filtered set; GeoJSON, KML and CSV import as a Dataset, revertible batch by batch; the Comuna
setting and the Unidad Vecinal cadastre import; Sectores drawn by hand or polygonized from picked
boundary features, with their Limit kept in words; assignment to Sector and Unidad Vecinal derived
from geometry and stored; the duplicate review queue; exports to GeoJSON, CSV and KML with their
log; and a Maki pictogram per Category. That is issues #1–#20.

Not working yet, and all of it visible on screen rather than in the model: legibility and
accessibility defects (#21–#25, #29, #35, #37), an editor for the taxonomy itself (#36), the
ODbL notice OpenStreetMap-derived exports owe (#28), and dead code with two silent re-import
defects (#34). Open issues carry the current list; this paragraph is a snapshot, they are the
record.

## Quickstart

Everything runs from this directory. Steps 1 and 4–6 need Docker; steps 2 and 3 need Node.

1. `make test` — the PHP core in a bare container. Proves the toolchain works: `--testdox` prints
   one line per behaviour and exits 0.
2. `npm install` — once, on the host.
3. `make build` — webpack writes `js/territorio-{main,admin}.js`.
4. `make instance-up` — a throwaway Nextcloud 34 + PostgreSQL 18 instance with this directory
   bind-mounted as the app. It prints `Instance ready at http://localhost:8083 (admin / dev)`.
5. Open <http://localhost:8083>, log in as `admin` / `dev`, and pick **Territorio** in the header.
6. `make instance-clean` when you are done — it removes the containers and their volumes.

## Install

Nextcloud **34 only** (`appinfo/info.xml` pins `min-version` and `max-version`), PHP 8.1 or later.

In the gestion stack, `./apps` is bind-mounted at `/var/www/html/custom_apps`, so installing is
cloning this repo to `gestion/apps/territorio` and enabling it. Both commands run from the gestion
checkout:

    git clone https://github.com/APS-Conecta/territorio.git apps/territorio
    docker compose exec --user www-data nextcloud php occ app:enable territorio

`js/` is committed — Nextcloud apps ship built assets — so no build step is needed to install.
Enabling runs the `EnsureSeedData` repair step, which writes the eleven Categories, the División
territorial category, their Subcategories, the revision cursor and the colour ramp. It is
idempotent and runs again after every migration.

## Configuration

Four settings, all app config under the `territorio` app id, all reachable at
**Administración → Territorio**. Read them with
`occ config:app:get territorio <key>`.

| Key | What it does | Default |
|---|---|---|
| `tile_url` | The Leaflet tile template the basemap draws. The Content-Security-Policy host is derived from it, so the two cannot drift. A string without `{z}`, `{x}` and `{y}` is stored verbatim but never served. | empty → `https://tile.openstreetmap.org/{z}/{x}/{y}.png` |
| `tile_attribution` | Plain-text credit shown over the map. Ignored while `tile_url` is rejected or unset, because we are then serving OpenStreetMap's tiles and owe OpenStreetMap the credit. | empty → `© OpenStreetMap contributors` |
| `comuna_cut` | The CUT code of the comuna this install serves. Empty means "not chosen yet" and the setup screen says so. | empty |
| `comuna_name` | The comuna's name, for display. Written together with `comuna_cut`. | empty |

The dev instance caches app config in APCu, which is per-process: see **Two traps worth knowing**
below before you trust `occ config:app:set`.

## Working on it

    make test             # PHP unit tests in a bare container — no Nextcloud needed
    make test-js          # the frontend's pure logic (filters, counts) — vitest
    make lint             # php -l over lib/, tests/ and tools/, plus the Category ramp's contrast and separation
    make lint-js          # eslint over src/ and tests/js — one rule: nothing undefined
    make test-browser     # drive the running app with Playwright (needs instance-up + build)
    npm install           # once
    make build            # webpack → js/territorio-{main,admin}.js
    make notices          # what actually ships inside js/, checked against THIRD-PARTY-NOTICES.md
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

## Asking Territorio things from another app

Apps on the same Nextcloud consume each other by injection, not over HTTP — it is faster, it joins
the caller's transaction, and there is no request to authenticate. Inject
`OCA\Territorio\Service\RegistryService`:

```php
public function __construct(
    private \OCA\Territorio\Service\RegistryService $territorio,
) {}

$counts = $this->territorio->counts();
$counts['categories']['organizacion_social']['subcategories']['taller'];   // how many talleres
$this->territorio->inSubcategory('organizacion_social', 'taller');          // and which ones
$this->territorio->find($id);                                              // one record, or null
```

Keyed by slug, never by id: ids differ per install, slugs are what the seed and the imports use.

Everything reads through the same set the app's own screens read, so retired records and the
Boundary-feature Datasets are absent — a comuna's eleven thousand streets are not places anyone
means to count. **No OCS route and no external API is registered**, and
[`tests/unit/RoutesTest.php`](tests/unit/RoutesTest.php) fails if one is added
([ADR-0014](docs/adr/0014-the-cross-app-surface-is-injection-not-rest.md)).

"How many sectores" and "how many unidades vecinales" are both ordinary Category counts: they are
Features under `division_territorial` (ADR-0010), so
`counts()['categories']['division_territorial']['subcategories']['sector']` answers the first and
`…['unidad_vecinal']` the second. Counting records **by** the Sector or Unidad Vecinal they fall in
is `counts()['bySector']` and `counts()['byUnidadVecinal']`, read from the assignment ADR-0008
derives from geometry and stores. Both are keyed by the boundary's own id, not by its name: a
clinic names its Sectores however it likes and two of them may share a name.

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

## Exporting

`Exportar`, beside the search, writes what the list is showing — layers and search applied — as
GeoJSON, CSV or KML.

GeoJSON is the one that goes back in: it carries the external ids, so re-importing updates rather
than duplicates. One exception, and it is deliberate: a Sector's **Limit** is written to the file as
a sentence but is not read back. Its parts name Boundary features by id, and those ids mean nothing
in another install — reading the sentence back would replace a Limit built from a dozen picked
streets with one line of prose. So "lossless" covers every field a record holds except this one,
which is exported to be read rather than to return. Export it **with** contact details if you mean to re-import it. A GeoJSON written
without them marks itself partial and the import refuses it, because its contact fields are absent
rather than empty and an import would read that as "these records have no telephone".

An export carrying contact details asks for your Nextcloud password and is recorded — who, when,
what format, how many records and what it was of. An administrator reads that list in
**Administración → Territorio**. Exports carrying none ask for nothing (ADR-0006).

## The basemap

Tiles come from OpenStreetMap by default. An administrator can repoint them at any tile server
(Settings → Administration → Territorio), including a self-hosted one for a clinic without
internet access — see [ADR-0004](docs/adr/0004-openstreetmap-is-tiles-and-offline-imports-only.md).

The Content-Security-Policy host is derived from whatever URL is configured, so the two can never
drift apart. A URL that is not a usable tile template is stored as typed but never served: the map
falls back to OpenStreetMap and the settings page says so.

## Licence

AGPL-3.0-or-later — see [`LICENSE`](LICENSE).

This is not a free choice. The app compiles `@nextcloud/vue` (AGPL-3.0-or-later) into the
bundles under `js/`, along with Nextcloud packages under GPL-3.0-or-later, so the copyleft
is inherited from what it links.
[ADR-0010 in gestion](https://github.com/APS-Conecta/gestion/blob/main/docs/adr/0010-agpl-across-the-org.md)
records the organisation-wide decision that followed from it.

Because the AGPL's section 13 covers network use, staff who use this app over the intranet
are owed its source on request.

Third-party components and their notices: [`THIRD-PARTY-NOTICES.md`](THIRD-PARTY-NOTICES.md).
