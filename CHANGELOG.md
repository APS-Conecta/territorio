# Changelog

All notable changes to Territorio are recorded here. Decisions behind them live in
[`docs/adr/`](docs/adr/).

## [Unreleased]

The basemap is OpenStreetMap's tile service, used as its Tile Usage Policy asks (ADR-0021).

### Changed

- **First signed release (0.80.0).** Releases are built and signed by the org's shared
  workflow (instalador #31), called from `.github/workflows/release.yml` pinned by commit
  SHA: a pull request builds the frontend and packs the tarball; a `v*` tag — which must
  equal `v` + the `appinfo/info.xml` version — signs `SHA256SUMS` with the app key inside
  the repo's `release` environment and publishes exactly four assets (`territorio.tar.gz`,
  `info.xml`, `SHA256SUMS`, `SHA256SUMS.minisig`), proven against the suite's `apps.pub`.
  The tarball's contents come from `.nextcloudignore`, added with this change: the
  development tree (`src/`, `tests/`, `docs/`, `tools/`, `scripts/`, the agent files, the
  configs) stays out; `appinfo/`, `lib/`, `templates/`, `img/`, the built `js/`, the
  vendored `vendor/aps/common/src`, the licences and the notices travel.

  **`js/` is build output now, not a committed artifact.** The five tracked bundle files
  (including the terser `.LICENSE.txt` notices) leave git and `js/` is ignored: the
  release builds the bundle before packing, and `pack-app` refuses a committed one. CI's
  rebuild-no-drift arm goes with them — the release workflow's pack step now fails a PR
  whose build produces no bundle — and installing from a clone needs
  `npm ci && npm run build` first (README says so).

  **The vendored docs checker and canon are gone** (`instalador #32`): the checker, its
  profiles and the documentation workflow under `.github/`, the issue forms, the
  pull-request template and the code-owners file — the org defaults in
  `APS-Conecta/.github` serve the community files now. No `guard.yml`: the repo is
  public, so secret scanning and push protection are GitHub's, not a workflow's.

- **The basemap is OpenStreetMap's tile service and nothing else (0.79.0).** One constant,
  `https://tile.openstreetmap.org/{z}/{x}/{y}.png`, loaded directly by each user's browser under the
  Tile Usage Policy (ADR-0021). There is no setting, no second form and no archive.

  **Removed with it:** PMTiles and `protomaps-leaflet` (14 packages leave the lockfile, 9 of them
  compiled into `js/` with attribution rows in `THIRD-PARTY-NOTICES.md`); the `tile_url` and `tile_attribution`
  settings; the «Fondo de mapa» form under Administración and its `PUT /settings/basemap` route; the
  `basemap` initial state; the `Basemap` and `BasemapConfig` classes; the vector basemap's
  brightness filter. The page's Content-Security-Policy now allows images from
  `https://tile.openstreetmap.org` and adds no `connect-src` host, whatever was configured before.

  **A repair step deletes the stale settings, and writes down what they held first.** On install and
  after every migration, `RemoveRetiredTileSettings` logs one warning line per key found (app, key, old
  value) and then deletes it. Warning, because a default Nextcloud drops anything below it. Nextcloud cannot downgrade an app, so that log line is the only record
  of the address a clinic had configured. A second run finds nothing and says nothing.

  **What a block looks like, because the app cannot tell you.** OpenStreetMap answers a blocked
  client with a valid PNG, which the browser loads without error: no banner appears and no
  `tileerror` fires, and the basemap shows an «access blocked» picture. The fix is a release that
  changes the one constant, since no setting remains to change.

  Check from a real clinic network, with the previous release installed, that OpenStreetMap's tiles
  load before this ships; the tile service is best-effort and is the only basemap left.

- **Documentation aligns with the org-wide standard.** `docs/index.md` becomes one Document ×
  Mode × Reader × Authority table, the README puts the operator chapters (install, configuration,
  deploying) ahead of the developer ones, and `appinfo/info.xml` gains a `<documentation>`
  element (admin and developer).
- **The basemap is a PMTiles archive this stack serves itself, not OpenStreetMap's tile service**
  (ADR-0019). One file, all of Chile, read straight from the browser over HTTP Range requests.

  **Because the public service answered `403`.** Not intermittently, and not for everyone:
  reachable from the server, refused from a clinic's network. A `403` there means *blocked*, and
  measurement said why this app was a candidate — see the `Referer` entry under 0.72.0. Their wiki
  files `tile.openstreetmap.org` as a **P2 best-effort** service under a Tile Usage Policy, and
  their own answer to a blocked application is to run your own tile server or use a third party.
  So the app now does, and the dependency that produced the 403 is gone rather than negotiated
  with: measured after the change, a full page load contacts `openstreetmap.org` **zero times**.

  **The archive is 1.04 GB and nobody downloads it.** All of Chile at zoom 0–15, bbox
  `-110.0,-56.0,-66.4,-17.5`. PMTiles is read by byte range, so a client pulls only the ranges
  holding the tiles it is drawing — measured over La Florida: **552 KB** for a screenful at z12,
  **863 KB** at z14, **952 KB** at z15, **2.3 MB** to work the whole comuna across all three. A
  ratio of about **1:460** against the file. Vector tiles also re-render at any zoom, so z15 data
  serves z16–19 with no further fetches, where raster refetched on every level.

  **The bbox includes the islands, and that was not free by accident.** A bbox stopping at the
  mainland (`-75.8`) silently excludes **Isla de Pascua** (lon −109.4) and **Juan Fernández**
  (−78.8) — two comunas with health facilities. Including them adds 368,000 tile entries and
  **4.7 MB**, because empty Pacific deduplicates away. Measured alternatives, same build: one
  comuna 5.7 MB, Región Metropolitana 82 MB. Chile-wide because this app is for multiple comunas
  and multiple CESFAMs; regional would save ~985 MB of a 90 GB disk and buy an install that only
  works in Santiago.

  **A basemap URL is now one of two forms and `Basemap::kind()` says which.** A `.pmtiles` URL
  carries none of Leaflet's `{z}/{x}/{y}` placeholders — and the placeholder test was exactly what
  decided whether a URL was a basemap at all, so before this the setting was *silently rejected*
  and the map quietly stayed on OpenStreetMap. The raster form stays fully supported, because
  ADR-0004's promise was that an administrator can repoint this at their own server.

  **The Content-Security-Policy directive follows from the kind.** Raster tiles are `<img>` loads
  and belong in `img-src`; an archive is `fetch`ed and belongs in `connect-src`. Getting this wrong
  fails in the worst way available — the host is named, the policy reads as though it allowed the
  basemap, and the map is blank.

  **Rendered with `protomaps-leaflet`, not MapLibre**, and that is a decision its own docs describe:
  they put the library in maintenance mode and point new projects at MapLibre "except for legacy
  projects that heavily depend on Leaflet". This map is Leaflet all the way down — geoman draws on
  it, markercluster clusters on it, `PICKABLE_PANE` orders it — and the same docs call the library
  suited to non-interactive layers, which is precisely what a basemap under our own overlays is.

  **Nine packages of attribution arrived with it.** `make notices` named every one on the first run
  after the install and failed until they were credited: the library, `@protomaps/basemaps`,
  `pmtiles`, `pbf`, `@mapbox/point-geometry`, `@mapbox/vector-tile`, `color2k`, `potpack`,
  `fflate`. Two of them ship no licence file at all and are marked † accordingly. Attribution to
  OpenStreetMap is unchanged and still required: the archive is an ODbL Produced Work and its own
  metadata declares `© OpenStreetMap`.

  **The refresh cannot be a pinned URL.** Protomaps' daily builds expire — `20260908` was already
  `404` nine days later. Extract once, serve locally, re-extract on a schedule.

  _Superseded by 0.79.0 and ADR-0021: the basemap is OpenStreetMap's tile service again, so a page
  load contacts `openstreetmap.org` for tiles and the archive, `tile_url` and the kind fork are gone._

- **Every measured legibility ratio was re-measured, and one of them needed the basemap changed**
  (`docs/design/map-legibility.md` § 2.2). All of § 2 was taken over raster OSM land, so the swap
  made every one of them a claim about a surface that is no longer there.

  Bare Protomaps `light` earth is darker than raster OSM land, which alone put the worst
  dot-on-tint pair at **2.65:1** — under WCAG 1.4.11's floor and under the **3.04:1** it replaced.
  No alpha rescues that: even a wash of zero reads 2.84:1, because what changed is the basemap's
  lightness and not the wash over it. A `brightness(1.09)` on the vector tile pane puts it at
  **3.14:1**, and leaves every other measured ratio *better* than it was on raster — park 2.98:1
  against 2.60:1, water 2.63:1 against 2.20:1.

  **Two things § 2 did not say, found while checking it.** Its `3.04:1` is the only surface it
  measured: over raster *water* the same pair read **2.20:1** and over *green* **2.60:1**, both
  under the floor, for as long as this app has existed. That is not a regression this change
  introduced — it is the first time anyone looked. What keeps it off the defect list is what § 2
  already said keeps it off: the pin's 2px border, so a dot never relies on fill-vs-tint contrast
  alone.

  `white` was measured too and clears the floor on land *and* park (3.44 / 3.38). It was rejected
  for something no ratio captures: it renders a basemap so pale the street grid is hard to read,
  and reading the street grid is how a Sector gets drawn (ADR-0007). The filter is scoped to the
  vector basemap, so a clinic still serving raster tiles keeps exactly the numbers § 2 documents
  for it — it has not agreed to a re-measurement.

  _Superseded by 0.79.0 and ADR-0021: the vector basemap and its filter are gone and § 2.2 no longer
  carries the Protomaps re-measurement; § 2's raster numbers stand._

- **The import path owns its label rule, and ImportFiles owns its bytes.** The `trim(...) ?: null`
  normalisation lived inline in two callers, bound only by a comment that named the other; it is
  `ImportService`'s now and both callers pass what they were given. The upload and Files-tree
  acquisition moved with it, out of the controller and into `ImportFiles` — the controller is
  wiring only, the same shape as its siblings, and the size cap and its one sentence are a single
  constant both doors share.

- **The KMZ temp-file dance lives once.** libzip cannot read or write from memory, so the import
  and the export each wrote the same tempnam + ZipArchive + finally-unlink routine independently.
  `Kmz` holds it now — the read side keeps its `InvalidImportException` sentences and the write
  side its `InvalidFeatureException`, so each caller's narrow catch still catches exactly what it
  always did. The KML is still rendered INTO the open archive, which is what the mid-write
  failure test proves.

- **The folding rule is defined once, and the twins are pinned by tests instead of comments.**
  `fold` existed in the Registry search, the street picker and the server's duplicate queue, held
  together by cross-referencing comments that could drift silently. The browser imports the one
  definition from `filters.js` now, and `tests/js/twins.test.js` reads the PHP source and fails
  loudly when either side's rule moves — the same doctrine as the map pins and the glyph tests.

- **The ring every pin and cluster bubble wears is one fixed dark ink, `#171717`, in both themes.**
  It was the theme's `--color-main-background` — white in the light theme — which measures
  **1.15–1.69:1** against the basemap tiles the ring actually sits on: under the 3:1 contour
  floor of WCAG 1.4.11, in both themes, since the first release. The tiles are light whatever
  the Nextcloud theme is doing, so the ring now follows the tile: `#171717` measures **≥ 10.6:1**
  over every raster and Protomaps surface and **≥ 3:1** over every legal pin fill — gated by
  `tools/ramp-check.py`, asserted identical across themes by the browser gate, measured rows
  recorded in `docs/design/map-legibility.md` § 2.2.

### Added

- **The map opens on the Comuna it serves (0.78.0).** With a Comuna chosen, the map opens on its
  extent, a box the app derives from the comuna's CUT code from a table it ships (ADR-0021), so
  an install provisioned before this release opens in the right place without being provisioned
  again, and a comuna an administrator changes moves the opening view at once. A comuna of
  several islands or pieces of land opens on the part where its health establishments are. The
  Unidad Vecinal bounds remain the next source, then the bounds of the records themselves, then the
  middle of the country; the hard-coded view of central Santiago is gone, which did not belong in
  a product that serves any comuna.
  Nothing restricts where a person may pan afterwards.

- **«¿Dónde estoy?» puts the person holding the phone on the map.** A button under the zoom
  control, Leaflet's own `locate({ watch: true })`, and no dependency.

  **The accuracy is the feature, not decoration.** This app decides which Sector and which Unidad
  Vecinal a point falls in (ADR-0008), and those boundaries are streets apart. A fix taken indoors
  is routinely accurate to hundreds of metres and arrives looking exactly as confident as a good
  one. So a fix is drawn with the circle it really covers, and past **100 m** it says in words that
  it is approximate and may not match the sector shown — 100 m because a block in La Florida is
  well under it. A reading with no accuracy attached is treated as untrustworthy rather than as
  perfect, which is the case where believing it costs most.

  **A dot with a white ring, not a pin, and not in a Category hue.** Hue groups Categories
  (ADR-0013), so a position in a ramp colour would read as a record somebody filed, and a pin would
  read as a filed Place. `#1a73e8` sits ΔE 0.113 from its nearest Category against the 0.05 floor
  at which two dots read as one colour, and 4.07:1 against the basemap.

  **`watch`, and it stops.** Somebody using this is walking a sector with a phone, so a one-shot
  fix is stale by the next corner — but a watch nobody turns off keeps the GPS awake, so the
  control toggles and `beforeUnmount` calls `stopLocate()` explicitly. `map.remove()` does not:
  the watch is a `navigator.geolocation` subscription held by the browser, not by the map.

  Error messages are in **usted**, like every other sentence this app shows a person. The first
  draft was voseo — «Podés», «probá» — which is not how anybody is addressed in a Chilean clinic,
  and nothing in the repo would have caught it, so a test now pins the register.

- **«Buscar una calle» finds a street and takes the map there.** A box under «¿Dónde estoy?»:
  type part of a street's name, choose one of the places it names, and the map fits to it, no
  closer than zoom 17. Street precision, which is what placing a record by its address needs
  before the click that places it. It searches the Cadastral Dataset the Sector editor already
  picks from and nothing else — no geocoder, and no request to any host but this server
  (ADR-0004).

  **One name in two places is two rows.** La Florida's cadastre holds «Pasaje Dos» in sixteen
  places, and one row for all of them would fit the map to the whole comuna, which is not a
  choice anybody can make (ADR-0007). So the server splits every name by place: its ways are one
  street while each comes within **500 m** of another (`BoundaryService::SAME_PLACE_M`). Measured
  on La Florida's 11,291 named ways, 416 names split into 1,031 places, while «Avenida La
  Florida», 128 ways laid end to end, stays one row. Under each name the row says which Unidad
  Vecinal its centre falls in and how many tramos it is — worked out in the browser from the
  Unidades Vecinales it already holds, whatever Capas or «Buscar» is hiding. Nothing is chosen
  for the person: Enter with no row active does nothing.

  **Names and boxes, never shapes.** `GET /boundaries/names` is the one read of the cadastre that
  no view bounds, and it is safe only because it carries no geometry (issue #12): about 5,100 rows,
  ~95 KB gzipped, fetched once, the first time somebody focuses the box. A plain route beside the
  Sector editor's, not OCS — the published surface stays at three (ADR-0020). The ranking is the
  Sector editor's own (`closerFirst`), so the app's two street lists agree about what comes first.

  **Nothing to find until a cadastre is imported.** Production has no Cadastral Dataset yet, and
  the box says so in words — «No hay calles cargadas en este mapa.» — rather than showing an empty
  list. Importing La Florida's (`occ territorio:import … --boundary`) fills this search and the
  Sector editor's at once. The browser gate imports two lines with one name two kilometres apart,
  chooses the second from the keyboard and reads where Leaflet actually landed, and measures the
  box under «¿Dónde estoy?» at 1400 px and at 360 px: with the `fitBounds` line removed, or the
  two controls mounted the other way round, it fails. Shipped as 0.77.0 (`?v=` on the script URL
  comes from `info.xml`).

- **The map page and the admin panel show their loading state instead of a blank frame** while the
  bundle boots — spinner + `<noscript>` from the suite theme (`themes/apsconecta`, MAPEO.md §5),
  the same component in every custom app. Vue's container mount replaces the mount's children on
  boot, so the stub removes itself. Before this there was nothing at all: a blank frame until the
  webpack bundle mounted, and nothing for a JS-disabled browser.

### Removed

- **Code nothing in production reached (0.79.1).** No behaviour changes. Every symbol was searched by
  exact name across `src/`, `lib/`, `tests/`, `tools/`, `templates/`, the docs and the README before
  it went.
  - `filterAssets` and `isPotentialDuplicate` in `src/assets.js`, and the tests that existed only
    for them. `filterAssets` repeated what `filterFeatures` and `listFor` in `src/filters.js` already
    do; `findDuplicateCandidate`, which the form calls, is unchanged.
  - `GLYPH_SLUGS` in `src/glyphs.js`; the only reader was a test, which now reads the `GLYPHS` table
    from the module's source.
  - The `counts` prop of `LayersDropdownButton` and its per-category badge: `App.vue` never bound it.
  - The `select-existing` event `FeatureDetail` declared and nothing fired or listened to.
  - Five `export`s that only their own file uses (`METRES_PER_DEGREE`, `SAME_PLACE_METRES`,
    `SAME_BLOCK_METRES`, `ALIKE_THRESHOLD`, `closerFirst`), and two unused PHP `use` statements.
  - `tests/js/deadcode.test.js` pins the absences, because ESLint flags an unused local but never an
    unused export, an unread prop or an emit nobody fires.

### Fixed

- **A pin click while placing places there (#196).** While a draft waits for its coordinate or a
  Sector for its «Generar» seed, clicking a pin now places there instead of opening that record and
  discarding the draft; pins skipped the `placing` guard the shapes have. While «Dibujar Sector» is
  armed, a pin click no longer opens its record either (#197).

- **A map click no longer moves a saved Place whose read-only card is open (#194).** Every
  Place's draft has no geometry (its coordinate is `lat`/`lon`), and the form's edit mode lived
  only inside `FeatureDetail`, so App took every open Place for one being placed. A map click
  silently rewrote the coordinate, the next «Editar» + «Guardar» moved the record and the
  server re-derived its Sector and Unidad Vecinal (ADR-0008), and a click on a Sector went to
  the map instead of opening it. App now owns the edit mode (`v-model:editing`), `placing` is
  true only for a new Place, one after «Editar» or a pending Sector seed, and `placeDraft()`
  writes only while `placing`. The browser gate's click-placement check now asserts that the
  Registry keeps the coordinate and the assignment, that a Sector click opens the Sector, and
  that a click after «Editar» still places the Place.

- **While «Dibujar Sector» is armed, a click on a Sector or a Unidad Vecinal places a vertex
  instead of opening it (#197).** Nor does that click move an open Place's coordinate any more,
  whether the drawing is then finished or cancelled: `TerritorioMap` asks Geoman whether it is
  drawing before it selects a shape or emits `map-click`, so a vertex click belongs to the
  drawing alone.

- **Undoing a batch no longer leaves the records it created on the map (#200).** A revert swept
  each boundary it retired or restored at once, under a «reasignación de límite» batch of its
  own, outside the revert's lineage. The walk then found that write on a Place the undone batch
  had created, took it for a colleague's and counted the entry as «omitidos»: the import read «ya
  deshecha» with the Place still live, a redo skipped the same way, and every boundary that moved
  something added its own batch, with its own «Deshacer», to the dialog's list. A revert now
  sweeps once at the end of its walk, inside its own batch, the way an import does (L1-13), also
  when the walk throws part-way. A boundary retired or restored from the sidebar still sweeps at
  once under a batch of its own.

- **Same-named rows without a coordinate no longer collapse into one record (0.80.1, #202).** A row
  with no id and no coordinate was identified by its name alone, so fifteen «Multicancha» rows at
  fifteen addresses imported as one record and «14 duplicados en el archivo», on every run. Such a
  row is now identified by its name and address, or its name and notes where it has no address
  (ADR-0022), so a KML placemark's description tells same-named placemarks apart. The same name at
  the same coordinate stays one place, and every coordinate-bearing id keeps its bytes.

  **One-time re-key on upgrade.** A repair step moves each record already imported under the
  collapsed id to its new one, so the next import of its file matches it and its Local override
  stays attached. It writes no Revision; a record whose new id is already taken in its Dataset is
  skipped and counted in the upgrade output. **A GeoJSON export made before the upgrade** carries
  the old id of a re-keyed record, and re-importing it creates a second record: export again after
  upgrading. The `externalId` the REST surface publishes changes once for those records.

- **An over-long value refuses the whole import, and nothing is written** (#203). The import's
  pre-check knew geometry, an empty name and «ubicación», but not the caps the write door
  enforces, so a 72-character phone in row 4 left rows 1–3 committed behind a 422 that named no
  row. A name over 255 characters and an explicit id over 128 were capped nowhere and reached
  PostgreSQL as a 500 after the same partial write. The pre-check now runs the write door's own
  name and text checks (`FeatureService::checkNameAndText()`, so each cap and sentence is still
  stated once) plus the external id's width, and the file is refused with «no se importó
  nada», naming each row and field: «Malo: El teléfono no puede pasar de 64 caracteres.». A
  Dataset name over 64 characters is refused beside the empty one, before the file is read,
  from the browser and from `occ`. Creating or updating a record with an over-long name answers
  422 with «El nombre no puede pasar de 255 caracteres.» instead of a 500.

- **History and Import batch times show in the viewer's local time (#211).** Nextcloud runs
  PHP in UTC, so a Revision's `changed_at` and an Import batch's `started_at` hold UTC
  wall-clock time with no zone, and the history panel and the Importar list printed it as if
  it were local: 3 to 4 hours ahead in Chile. The API now sends both with their offset
  (ISO 8601, as the export log already did) and all three lists share one formatter. The
  Limit provenance sentence dates a save on the local day it happened, so an evening save no
  longer reads as the next day. Stored rows are read as UTC and none is rewritten.

- **An import Folder or Categoría that names only a shared Subcategory or a division word lands in
  «Sin clasificar · Otro» (#204).** «Otros» used to file under «Comercio y economía · Otro», and
  «Sector…» or «Unidad vecinal» under División territorial. A plural in «-es» («Parques») now finds
  its Subcategory. Re-importing an older file moves the rows filed that way to Sin clasificar;
  rows a person edited keep their edits (ADR-0003).

- **«Centrar» brings a Sector, a Unidad Vecinal or a Route into view, not only a Place** (#199).
  It read the draft's `lat`/`lon`, which a record with no Point geometry leaves empty, so on
  those records it did nothing. It now moves the map from the stored record exactly as opening
  it from the list does: pan to a Place at zoom 17, fit a Zone or a Route. An unsaved edit no
  longer changes where it goes. The browser gate opens a Sector, a Unidad Vecinal and a Place
  from the list, drags each out of view, and asserts where Leaflet lands after «Centrar».

- **«Cancelar» on a saved record discards the edit (#195).** The record reopens as the
  Registry holds it, so cancelled edits no longer reach a later «Guardar». A Sector shown in
  the read-only card has no vertex handles; «Editar» brings them back.

- **Restoring a Merged record reopens the pair it lost, not one it won (#206).** The lookup took
  the newest Merged pair the record was on, so a record that had survived one merge and lost a
  later one reopened the pair it won. That pair then sat pending but hidden, because its other
  member was still retired, and the pair actually undone stayed Merged and was never offered again.
  The lookup now names both members, the restored record and the survivor its `mergedInto`
  points at, and matches only while that pair is Merged.

- **A save that lands after the record was left no longer reports a false error or carries
  over the wrong picks (#198).** `save()` applied its response to whichever draft was open
  when the response arrived. After «← Volver a la lista» there was none: the save the server
  had accepted said «No se pudo guardar…», and the record stayed out of the list until the
  next poll. With another Sector open, the panel jumped back to the saved one carrying the
  other Sector's picked lines. The response now always reaches the list, and rebuilds the
  draft only if that draft is still the one that was sent.

- **A restored record derives its own Sector and Unidad Vecinal again (#201).** A restored record
  kept the Sector and Unidad Vecinal it had when it was retired, because boundary sweeps skip
  retired records. That left it pointing at a boundary retired since, or unassigned although a
  boundary drawn since covers it. `FeatureService::restore()` now derives the assignment again
  (ADR-0008), and the change lands on the `Restored` history entry. This holds for every door:
  «Ver retirados», merge undo, and redo of an undone import.

- **Adding the name a renamed Category or Subcategory was created with names the row that holds
  it (#210).** The refusal said «Ya existe … con ese nombre» although no shown row carried that
  name. It now says, e.g., «Ese nombre está reservado por la categoría «Culto». Elija otro
  nombre.»

- **The OpenStreetMap basemap follows the Tile Usage Policy to the letter (0.78.1).** The
  policy (https://operations.osmfoundation.org/policies/tiles/) is what keeps an instance from
  being blocked, so each rule it states is now either behaviour or a check:
  - *No scan.* A `flyTo` from zoom 12 to 17 asked for tiles at every zoom it crossed: **255
    requests**, measured. With `updateWhenZooming: false` it asks for where it lands: **65**.
    Zoom is limited to 8–18, and the layer does not wrap.
  - *Do not hammer a refusing host.* The retry ladder (1, 4, 12 s) kept its per-tile bound but
    every failed tile climbed it together: against a host that answered 503 a page sent **170
    requests in 24 seconds**. The page now has one budget of 16 retries, spent when the retry
    fires and refilled by one for each tile that loads.
  - *Attribution not under other UI.* On a phone the docked panel covered the whole credit
    (measured at 360 and 320 px). Below 810 px the panel now stops short of it.
  - *Never scrape OpenStreetMap from a test.* A unit test that reaches `openstreetmap.org` now
    fails instead of connecting. The browser gate asserts what it sent (a bare https URL, a
    Referer on every request, at most one screen of tiles, an `img-src` naming only the tile
    host), measures the credit at five widths with the panel open and collapsed in both
    themes, and checks that a tile service that is down costs at most the budget and that the
    app recovers when it returns. A block by OpenStreetMap arrives as a 403 carrying an image, which
    a browser loads without error: the app cannot see it, and a test pins that so the runbook
    note stays true.

- **The dev map is no longer grey, and the browser gate no longer touches OpenStreetMap.**
  `make instance-up` pinned `tile_url` to a PMTiles archive on a tailnet host, so the map was
  blank on any machine that cannot reach it, and `protomaps-leaflet` raised no error to say so.
  The instance now serves the app's own default and clears what an earlier run pinned. The
  gate answers OpenStreetMap's tile requests from a generated tile, refuses anything else
  addressed to `openstreetmap.org` and resolves the host nowhere as a second layer, so a run
  never contacts the tile service (its Tile Usage Policy forbids scraping). It also gives the
  dev `admin` a status: Nextcloud's front end logs a console error for a user with none, and the
  gate counts console errors. A full run on a clean instance now exits 0 (it exited 1 on three
  basemap failures); the two cadastre SKIPs remain.

- **Typing a street no longer empties the list (0.77.4).** The top search box also wrote the
  list's filter, so a street name (which no record carries) showed «Nada coincide con la búsqueda».
  It now only offers matches (looking through the Capas-filtered set, not the list's query), and
  the two boxes no longer mirror each other; the list keeps its own search box. The Índice drew
  every cell empty (it read `row.name` where rows are `{ id, cells }`); it reads the cells again,
  with their `data-key`, title and keyboard-reachable first cell. Also removed the street helpers
  the old control left behind (`placesMatching`, `nextActive`, `chosen`, `cornersOf`) and their
  tests, and corrected the comments that claimed the client's name similarity mirrors the server's
  (it is Levenshtein here and `similar_text` there; the server decides, ADR-0011).

  **On a phone the layers and draw buttons no longer cover the record's «← Volver a la lista».**
  Below 810px they wrap under the search box, and the panel's clearance (124px) assumed one row,
  so they sat on the record's first line at 360px. The clearance is one variable now
  (`--territorio-topbar-clearance`, 188px below 810px) shared by the panel and the full-screen views.

  Also deleted `IndiceView` and `AnalisisView`: each only forwarded its props and events to
  `IndexView` / `AnalysisView`, which `App.vue` now mounts directly.

- **No comuna or clinic is baked in (0.77.3).** The installer chooses the comuna and the
  establishment, so nothing the app shows or ships should pre-suppose one. The admin page no longer
  says «La Florida es 13110» and no longer suggests `13110` / «La Florida» as the code and name;
  the import dialog no longer suggests the dataset name `laflorida`; the CUT error no longer cites
  a code. The seed taxonomy's comment no longer says it was drawn from one comuna's catastro, and
  the tests use neutral names («Comuna de Prueba», «Sector Norte», dataset `catastro`) in place of
  a real comuna's. Measurements that cite La Florida as their source, in ADRs and docblocks, are
  left: they say where a number came from, and rewriting them would falsify it. Shipped as 0.77.3
  so the rebuilt bundle reaches browsers that have the stale one cached.

- **One street search, and one way to draw a sector (0.77.2).** The redesign left two street
  searches on the map (Leaflet's «Buscar una calle» beside the Omnibox) and Geoman's own toolbar
  button beside «Dibujar Sector». The Omnibox is the street search now, and it keeps what the old
  control had: a row says where a street is and how much of it there is («Villa Los Quillayes ·
  3 tramos»), so sixteen «Pasaje Dos» can be told apart; a pick goes no closer than zoom 17; the
  streets load once, and a failed load is retried by the next keystroke, with the same four
  messages («Cargando las calles…», «No se pudieron cargar las calles…», «No hay calles cargadas
  en este mapa.», «Ninguna calle con ese nombre.»). Drawing a sector is «Dibujar Sector» alone.
  The street list (about 5000 rows) is no longer made reactive row by row.
  `tests/js/search-control.test.js` became `omnibox-streets.test.js`, carrying over what it pinned
  (Enter takes the highlighted row or nothing, names are text and never markup, the plain route,
  the unfiltered Unidades Vecinales for the hint); the two browser checks of the old box are
  replaced by one in `--only redesign` that offers two same-named streets two kilometres apart and
  lands on the one chosen. Shipped as 0.77.2 so the rebuilt bundle reaches browsers that have the
  stale one cached.

- **The redesigned topbar does what it shows.** PR #171 wired `App.vue` to events and
  props its children never declared. Vue drops an unknown listener and turns an unknown prop into
  a DOM attribute, both without a word, and no test mounts a component, so 437 tests and CI stayed
  green over a search box, a set of view tabs, «Dibujar Sector», «Todas»/«Limpiar» and a taxonomy
  drawer that did nothing. Wired to what each component really declares; and the wiring is now
  pinned by `tests/js/appWiring.test.js`, which reads `App.vue` and every child it uses and fails
  on a listener the child never emits, a prop it never declares, or a required prop left unbound.

  Behind the wiring, what only a browser showed:

  - **The search box could not be clicked.** The topbar's `pointer-events: none` was inherited by
    the box and its tabs, so a click landed on the panel underneath. The three controls also
    placed themselves with fixed `left` offsets on top of the topbar's own layout, which put
    «Tipo de Activo» over «Dibujar Sector».
  - **Picking a result moved nothing.** A record carries GeoJSON `geometry` (`[lon, lat]`, or
    `null`), never flat `lat`/`lon`: a Place is panned to, a Sector or Unidad Vecinal is fitted to,
    and a record with no coordinates is selected and the map stays put. A street result lost its
    `south/west/north/east` on the way out of the matcher.
  - **The retired filter and the duplicate check read `retiredAt`**, a field that does not exist;
    the API sends the boolean `retired`. Their fixtures had the same invented shape, which is why
    they passed.
  - **The taxonomy drawer covered the page from the first paint**, because it was always mounted
    and has no `open` prop.
  - **The layers menu looked for `division-territorial`** where the slug is `division_territorial`,
    and hard-coded «Sectores CESFAM»; the division toggles now come from the taxonomy.
  - A second search typed after choosing a result showed no list: choosing a result cleared the
    box's focus flag while the input kept the real focus.
  - The Índice, Análisis and duplicates views covered the topbar they are switched from, and
    picking a record in one of them opened it in a panel nobody could see: it now goes back to the
    map first. A failed merge in the duplicates queue shows its error there too.
  - A click on the map selects a record and no longer also moves the map; only the search and the
    list pan to what they pick.
  - The taxonomy drawer forwards `clear-taxonomy-message`, so a refused rename stops showing once
    the next row is touched; the layers menu scrolls when its footer would fall off the screen;
    a partly hidden category's checkbox no longer ends up opposite to what is shown; a duplicate
    check on coordinates typed with a decimal comma works.
  - The panel's list was pinned to 300px by Nextcloud's own `.app-content-list`.
  - **An opened record could not be closed.** The panel shows the list or a record, `App.vue`
    listened for a `cancel` that `FeatureDetail` declared and never sent, and the sidebar's close
    button went with the sidebar. «← Volver a la lista» is the way back.

  The ADR and design comments the rewrite deleted from `App.vue` are back, and the second error
  boundary (L0-02) around the open record's form is too. `tools/browser-check.py --only redesign`
  drives all of the above in a browser. Shipped as 0.77.1 so the rebuilt bundle reaches browsers
  that have the stale one cached (`?v=` on the script URL comes from `info.xml`).

- **The archive's reachability is checked directly, because there is no `tileerror` to hook.**
  `protomaps-leaflet` calls Leaflet's done callback as `done(undefined, tile)` unconditionally, so
  a tile that never rendered is indistinguishable from one that did, and the
  «No se pudo cargar el fondo de mapa» warning would never have appeared for a vector basemap —
  a wrong URL would be a blank map with nothing in the console.

  So the first seven bytes are read once, at mount, and they have to spell `PMTiles`. That checks
  three things a status code does not: that the server honours Range (**a 206, specifically** — a
  server that ignored it would make the reader pull a gigabyte per tile, so the body is cancelled
  unread rather than measured), that something is actually there, and that what is there is an
  archive rather than an error page served with a good status.

- **A crafted route id no longer answers a 500.** `PUT /features/retired` and its siblings used
  to TypeError before the method body ran — a 500 about a request that names no record; all seven
  `{id}` routes now answer the same 404 «no existe» a missing record does. And a merge without a
  `survivorId` answers «Indique cuál registro sobrevive.» instead of pretending one was named.
- **The browser gate fails, not skips, on an instance that is not on the PMTiles basemap**
  (#141). The arm landed with a skip for instances deliberately on OpenStreetMap; the review's
  point (L1-03) was that the gate must exercise the branch production runs, and a skip is a
  blind spot with a nicer name. `make instance-up` provisions the archive, so a gate run
  against OSM is a provisioning mistake and is reported as one. Proven on a freshly recreated
  instance (`make instance-clean && make instance-up && make test-browser`).
- **The history panel says «Sector: Sur → Norte», like every other surface** (review T5). A
  reassignment used to read «Sector: 41 → 58», raw record ids, while the Índice's columns and
  the sidebar's derived Sector/UV line already resolved them; the sidebar now hands its
  divisions one component down and the panel resolves through the same `nameOf`.
- **Comments and contracts say what the code does, and the five taxonomy writes share one
  door.** `rename`, `chooseGlyph`, `addCategory`, `addSubcategory` and `removeFromTaxonomy`
  each repeated the same tree-swap-plus-catch and three of them rebuilt the same URL; one
  `writeTree()` owns the swap, the message clearing and the 422-or-fallback rule now. The
  pickable-lines docblock claimed the group was «swapped whole like every other group here»
  — false since markers and shapes became diffed; it now says why this one still swaps. The
  sidebar's `hasShape` tested for an `undefined` geometry the draft contract cannot produce.
  Two PHP docblocks drifted during the perf pass: `findAll()`'s (with its ponytail ceiling)
  sat orphaned above `findByIds()`, and `apply()` carried its `@param` line twice. And `make
  lint` no longer parses the openapi extractor's vendor tree that `make openapi` fetches under
  `tools/` — its own fixtures do not parse under PHP 8.3, so lint failed on every box that had
  ever regenerated the spec and passed on every box that had not.
- **A render error in the sidebar loses the sidebar, not the app** (review T1). The
  ErrorBoundary wrapped the main column only; FeatureDetail and the Limit picker — which draw
  geometry- and limit-derived drafts — sat outside every boundary, so a bad shape there
  blanked the whole page including the navigation the boundary exists to preserve. A second
  boundary wraps the sidebar's content (the header and its close button stay outside, so a
  crashed sidebar can still be closed), and both boundaries now offer **Reintentar**, which
  re-renders without a reload; a development build also shows the error's message under the
  sentence. The error still propagates to the console either way.
- **Every load that can fail now says so and offers Reintentar** (review T2). The app's own
  rule — a failed load is not an empty list (L1-28) — held for history, duplicates and retired
  records and nowhere else. The catastro lines behind a Limit (an empty picker and a grey
  Generar over a network failure), the recent-imports list (a failed list read as «no imports
  yet», hiding Deshacer exactly when it mattered), the administrator's export log (an error
  card with no way back but a reload) and the pictogram catalogue all wear the same sentence
  and retry now. The catalogue was the worst of the four: `loadCatalogue()` cached a rejected
  chunk import for the whole session, so one blip while opening the picker left every glyph
  button disabled until a reload, with nothing said. A rejection is forgotten now, and the
  panel catches it.
- **The browser gate runs the basemap production runs.** Every automated check ran stock
  OpenStreetMap, the one configuration where `attributionHtml` short-circuits — so the branch
  the clinic actually runs (self-hosted PMTiles: attribution escaping, the CSP `connect-src`
  derivation, the archive watch) was dead code to 423 unit / 339 js / 214 integration tests and
  a twice-green gate while every production load crashed on a missing export (0.75.1). Now
  `make instance-up` points the throwaway instance at the same archive (`TILE_URL`, restarted
  in because APCu is per-process), and the gate's first check compares the served basemap with
  what the map painted: a vector tile on canvas, the served attribution in the control, no
  unreachable banner. An instance deliberately on OSM is reported as skipped, not passed; the
  hard-fail posture waits for a clean fresh install.


- **The app no longer crashes on load under the self-hosted basemap, and a broken import
  cannot build again.** `TerritorioMap` imports `escapeHtml` from `pins.js`, and the
  twelve-hardenings move that put the function there shipped without its `export` — webpack
  5 reports a missing export as a **warning** and compiled the binding undefined, so the
  attribution line threw `TypeError` inside `mounted()` and the whole main column fell into
  the ErrorBoundary. Only the custom-basemap branch calls it, and every automated check
  runs stock OSM (the branch that short-circuits), so 423 unit / 339 js / 214 integration
  tests and a twice-green browser gate never noticed. The export lands with
  `strictExportPresence` (one line in `webpack.config.js`), which makes a missing export a
  **build error** instead of a warning — the class of defect cannot compile. Shipped as
  0.75.1 so the rebuilt bundle reaches browsers that had the broken one cached (`?v=` on
  the script URL comes from `info.xml`).
- **«¿Dónde estoy?» is allowed to ask, and lets go when it is refused.** The control was dead on
  every Nextcloud 34 page: core attaches a bare `FeaturePolicy` to every `TemplateResponse`, and a
  bare one says `geolocation 'none'`, so every click failed with code 1 — permission granted or
  not — before the browser ever asked. Measured on the dev instance; never seen by three green
  suites and a twice-green gate, because nothing in them rendered a page and read its headers.
  The page now grants geolocation to itself, and only to itself, set beside the CSP it belongs
  with. The idea is nextcloud/maps' (`maps@0cc19b0:lib/Listener/AddFeaturePolicyListener.php:27-28`,
  ADR-0001: adapted, not depended on), expressed as two calls to Nextcloud's public API rather
  than a listener, because one page needs the grant.

  And an error stops the GPS. Leaflet fires `locationerror` and keeps its watch, so an error
  handler that only flipped the button left the first `watchPosition` alive and the next click
  started a second one on top of it — measured: two refused clicks, `watchPosition` ×2,
  `clearWatch` ×0. `stopLocate()` is now the first line of the handler. The browser gate reads
  the `Feature-Policy` header off the document response and counts `watchPosition`/`clearWatch`
  through a refused click, a granted one and the stop, so either fault can only break loudly:
  on the tree before this entry the gate fails on the header, and with the one line removed it
  fails on the count. Shipped as 0.76.1 so the rebuilt bundle reaches browsers that have the
  leaking one cached (`?v=` on the script URL comes from `info.xml`).

## [0.72.0] — unreleased

The Category colour tests stop describing the instance they happen to run on.

### Added

- **A render error in the main column now shows *«No pudimos mostrar esta sección»* instead of a
  blank page.** The map is mounted with `v-show` so it survives a pane switch, which also means it is
  mounted from first paint whatever pane is showing — so one throwing render took the switch, the
  Índice and Análisis down with it, and an empty page looks exactly like the instance being down. The
  Capas panel and the Datos list are outside the boundary and keep working as navigation. The error
  still reaches the browser console, so the fallback does not cost a diagnosis. It covers render,
  watcher and lifecycle errors — not a throw inside a click handler or a rejected promise.

### Fixed

- **The map now tells OpenStreetMap who is asking, which is what a `403` means it was not doing.**
  Nextcloud serves every page with `Referrer-Policy: no-referrer`, so tile requests went out with no
  `Referer` header at all — measured, on the real instance. A browser supplies its own `User-Agent`,
  naming Chrome and not this app. So both of the things OpenStreetMap's Tile Usage Policy asks an
  application to identify itself by were absent, and the traffic was indistinguishable from
  anonymous scraping — the profile they rate-limit and then answer `403` to. The tile layer now sets
  `referrerPolicy: 'origin'`, which Leaflet documents for exactly this case ("your map's rendering
  context has a strict default but your tile provider expects a valid referrer"), and the requests
  carry `Referer: https://<instance>/`.

  **`origin`, not the browser default.** It sends the scheme, host and port and never a path, so a
  third party learns which instance asked and never which page of it was open or which record was on
  screen. Measured both ways: with no element policy the header is absent, with
  `strict-origin-when-cross-origin` the full path went out, with `origin` only the host does.

  This is not a promise that a `403` goes away. OpenStreetMap's own answer to a blocked application
  is that heavy users must run their own tile server or use a third party — the escape ADR-0004
  already built, since the tile URL is an administrator setting. Identifying ourselves is the part
  that is this app's to fix.

- **A basemap tile that failed is asked for again, so the map stops keeping permanent grey holes.**
  Leaflet never retries one, and not by oversight: `TileLayer._tileOnError` hands the tile straight
  to `_tileReady`, which stamps `tile.loaded` with a timestamp and fires `tileerror` — and a stamped
  tile is a finished tile, so no later pan, resize or five-second poll re-requests it. Measured on
  this app with 40% of tiles answered `503`: 10 of 16 blank, and still 10 of 16 blank twelve seconds
  after the host recovered *and* after a resize. The hole was permanent for the life of the page.
  The same run now ends 0 of 16 blank, healed with no user interaction.

  **It matters because of what the tile host is.** OpenStreetMap's wiki files
  `tile.openstreetmap.org` as a P2 best-effort service under a Tile Usage Policy, answers `429` when
  it rate-limits, and publishes a list of applications it blocked outright for heavy use. A tile
  failing now and then is the contract ADR-0004 accepted when it made the basemap a public service,
  not a fault. The fix is not OpenStreetMap-specific for that same reason: a clinic's own tile
  server drops requests too, and Leaflet will not retry those either.

  **Bounded and backing off — 1 + 3 attempts per tile over ~17 seconds, then stop.** That is a
  safety property rather than a tuning knob: an unbounded retry against a server already refusing is
  precisely the behaviour OpenStreetMap blocks applications for. Measured against a host refusing
  everything, each of 12 tiles took exactly 4 requests and the wire then went quiet. The url is
  re-requested unchanged, with no cache-busting parameter — a unique query string would miss OSM's
  CDN on every retry and turn each failed tile into a guaranteed origin hit, the opposite of what
  their policy asks for.

  **And it stops retrying altogether once eight tiles have failed outright.** A handful of holes is
  a blip worth re-asking about; that many is the host refusing us, and answering a refusal with four
  times the requests is how an application deepens its own block. Measured against a host answering
  `403` to everything: the first screenful costs 3.67 requests per tile, and then panning across 158
  further tiles costs exactly **1.00 request per tile** — no amplification at all. Without the
  breaker that same pan would have been some 630 requests aimed at a server already saying no.

  **The «No se pudo cargar el fondo de mapa» warning is untouched, and still correct.** It needs
  *no* tile to have ever loaded, so it stays quiet for a handful of holes and still appears for a
  host that is genuinely gone — verified while the retries were exhausting. A `404` past the edge of
  coverage reaches the same handler indistinguishably, because an `<img>` error event carries no
  status; three wasted requests for a tile that does not exist is the price of not needing to know
  which kind of failure this was.

- **`CategoryCrudTest` stops asserting that a spare colour is free** (#134). Four of its tests
  opened by claiming the instance had an unused spare, and on the dev instance all eight are held:
  the browser gate leaves one Category per run, each of them files a record, and ADR-0016 refuses a
  removal while a record is filed under a Category — retired ones included. So the pool drains and
  never refills, every new Category correctly takes the neutral, and the suite went red on `main`
  with an empty working tree for a reason that had nothing to do with the app. It is the fifth
  appearance of one shape (#58, #60, #117, #121, #122) and the first where the contended thing is a
  finite resource rather than a coordinate.

  **The assertions are now relative to the pool the instance is already in.** One helper states the
  rule — the first free spare, or the neutral once they are all held (ADR-0017) — and each test
  pins the colour the app owed at the moment it asked. That is the same statement in both
  directions: on a fresh instance it says «the next unused spare», on a drained one it says «the
  neutral», and an implementation handing out a spare somebody already holds fails it either way.
  The leftovers are left standing rather than cleaned up. They *could* be cleaned — the
  record filed under each one is moved to «Sin clasificar · Otro» and the Category then comes away,
  which is the route ADR-0016 tells a person to take, since a Feature cannot be un-created through
  the app at all (ADR-0005) and only ever moves. But that is an act on one instance, and the browser
  gate makes another leftover on every run, so a suite that needed the pool clean would be red again
  within the week. Nothing re-reads them except the surfaces that read any Category, where they
  appear as ordinary Categories on the neutral; `make lint`'s ramp check reads the seed, never the
  instance.

  **What a drained instance cannot show is measured somewhere it can be.** «Two Categories take
  different colours» and «the spare comes back on removal» are not false once the pool is empty,
  they are unobservable — every implementation, right or wrong, answers with the neutral. So the
  spare pool becomes an input in a new unit test with mocked mappers (`TaxonomyColourTest`): half
  taken, all eight taken, and one freed by a removal are three states written down rather than
  three states waited for, and they run on every `make test`, including CI, which does not run the
  integration suite at all. Without it, making the integration tests survive a drained pool would
  have meant quietly giving up the properties they were written for.

## [0.71.0] — unreleased

A file the clinic already has in Nextcloud Files need not be uploaded a second time.

### Added

- **A file already in Nextcloud Files can be imported without being uploaded again** (#104).
  «Desde archivos de Nextcloud» in the import dialog opens the platform's own file picker; the
  chosen file is read from the caller's own tree and handed to the very call an upload makes. So
  the suffix picks the reader — a KML in Files imports as a KML — and the run is one revertible
  ImportBatch like any other (ADR-0009). This does **not** make a Files folder a source of truth:
  the Registry is the single shared body of records and a local edit still outranks anything a
  re-import carries (ADR-0003). It is «re-importar el mismo archivo sin volver a subirlo».

  **No new service and no new dependency.** The branch is two calls in `ImportController`, because
  a service with one caller is an interface with one implementation. In the browser it is
  `OC.dialogs.filepicker`, which Nextcloud 34 still ships: `@nextcloud/dialogs` is not installed,
  and the `NcFilePicker` that is wraps an `<input type="file">` and hands back browser `File`
  objects — which is the upload again, wearing a different coat.

  **The request carries a path, and the caller's own user folder is the whole access rule.** A file
  somebody shared with the caller is mounted there, so it is importable by design rather than by a
  missing check; a path that is not theirs does not resolve. Every file-system exception on that
  road is caught and answered as the Spanish 422 the dialog knows how to show — nothing in this app
  had ever touched Files, so each of those was a 500 until this slice, including the plain case of
  a path that is no longer there.

  **The 8 MB cap applies here too, and is checked before the bytes are read.** It was enforced only
  on the multipart upload, and `getContent()` loads the node whole into PHP's memory — so the size
  is read off the node first, with the same constant and the same sentence an oversized upload
  gets.

## [0.70.0] — unreleased

The Registry becomes a table: a fourth pane that reads down a column.

### Added

- **El Índice: the Registry as a table** (#105). A fourth pane beside Mapa, Análisis and Posibles
  duplicados, showing the filtered Features as rows and their fields as columns — Nombre,
  Categoría, Subcategoría, Sector, Unidad Vecinal, Dirección, Teléfono, Correo, Exactitud and
  Revisión. Clicking a header sorts by that column, ascending and then descending; clicking a row
  opens the record; a row of checkboxes takes columns away and brings them back. Nothing before it
  let a person read the catastro down a column, which is what checking it against a municipal list
  actually looks like.

  **It narrows nothing, and that is the feature.** There is one filtered set in this app and the
  map, the Datos list, the Análisis counts and Exportar all read it, so the table reads the same
  array and adds no per-column filters. Sorting is safe precisely because it changes the order and
  never the membership — so «Exportar lo que está viendo ahora» keeps meaning what it says without
  a line of new code. A second narrowing path is how the table would come to show one number while
  Exportar carried another.

  **Names, never ids.** Sector and Unidad Vecinal are stored as Feature ids and the classification
  as a Subcategory id, so the sorting is done on the RESOLVED value: ordering the Sector column by
  the id would order it by which boundary happened to be imported first — invisible, and wrong in a
  way nobody on screen could catch. A Subcategory the taxonomy no longer has still refuses to read
  «Sin clasificar», which is a real seeded Category (FeatureList's rule, kept).

  **`Revisión` and not a «fecha de modificación».** A Feature carries no timestamp to the client,
  only its integer revision; the date lives in the revision log, one request per row, and is its
  own issue.

  **The pane is rebuilt on every switch, so its View lives in App.vue.** The column checkboxes are
  labelled «Nombre», «Teléfono», «Correo» — the record form's own words — so a pane left mounted
  behind the map would put two controls with one accessible name in the page at once. Which columns
  are hidden and how the rows are ordered therefore sit beside the filter, where the personal View
  already lives; hidden columns rather than shown ones, for filters.js's reason: a column added
  later appears on its own.

## [0.69.0] — unreleased

An import can be a KMZ, which is the KML this app already reads, in an archive.

### Added

- **KMZ joins the import formats** (#103). The archive people are actually sent: Google Earth
  exports one by default and MyMaps offers it beside the KML. It adds no reader — the archive is
  unzipped and the KML inside goes to `KmlReader`, so the batch, the matching, ADR-0003's override
  rule and ADR-0009's revert are the one implementation they already were, and a KMZ import is a
  KML import in every way that can be observed afterwards.

  **The document is the FIRST `.kml` entry, not `doc.kml`.** That name is Google Earth's
  convention; the format's rule is the position, and QGIS, ArcGIS and several exporters write
  `<capa>.kml`. `getFromName('doc.kml')` returns false for those, so asking by name would have told
  somebody their perfectly valid KMZ held no map — blaming the file for our assumption, which is
  the same mistake the KML reader made once about namespaces. An icon or an overlay image sitting
  ahead of the document is ordinary, so "the first entry" and "the first `.kml` entry" are
  different rules and only the second is the format's.

  **No dependency, and a temporary file that is the call's own.** `ZipArchive` is PHP's own and is
  in the runtime image already — #102 put it there for the export. It cannot read from memory,
  though: `open()` takes a path, and what the ingestion path carries is a string, because both the
  browser upload and `occ territorio:import` read the bytes before calling the service. So the
  bytes go through the temporary directory, and the `finally` — not the end of the request — is
  what removes that file on the refused path as well as the imported one. A refused import is
  precisely where files would otherwise pile up, because that is the path nobody watches.

  **The lossiness notice covers the KMZ, which was the part worth getting right.** The import
  dialog keys its warning on a KML-family rule now, the shape #102 gave the export dialog. A KMZ
  carries exactly what the KML inside it carries — no phone, no email, no contact, no hours — so a
  notice that appeared for one and not the other would have told a person that zipping their MyMaps
  export brings their telephone numbers back.

  **Refusals are sentences, not stack traces.** A `.kmz` that is not an archive and an archive with
  no `.kml` in it are both answered in Spanish, before a Dataset is created and before a single row
  is written. Without the first of those the person got `ValueError: Invalid or uninitialized Zip
  object` through a 500.

## [0.68.0] — unreleased

A spreadsheet is a file this app can read: CSV joins GeoJSON and KML on the way in.

### Added

- **A CSV can be imported, the way GeoJSON and KML already can** (#101). One file, one Dataset, one
  revertible ImportBatch (ADR-0009), through the same dialog and the same `occ territorio:import`.
  What it is for is the spreadsheet a clinic sends — a catastro somebody keeps in Excel is a real
  file that had no way in, and asking a health team to convert it to GeoJSON first is asking them
  not to send it.

  **The reader does not build rows.** `CsvReader` turns the file into a GeoJSON FeatureCollection
  and hands it to `GeoJsonReader`, exactly as `KmlReader` does, so a CSV import IS a GeoJSON
  import: the same derived external ids, the same refusal of a nameless row, the same `category` /
  `subcategory` pair `classify()` files on, the same comuna claim. A second row builder is how the
  formats would quietly start importing differently — and it is proven where it matters rather than
  asserted, by importing the same record twice, once as CSV and once as GeoJSON, and requiring the
  second run to report it **unchanged**. `differs()` compares the whole official blob and then every
  field, so one value arriving under a different key or trimmed differently would show up there.

  No `TabularReader` interface came with it. There is no interface anywhere in `lib/`: the readers
  share `read()` by convention and the third one is one more branch in `ImportService::importFile`,
  not a hierarchy with one implementation.

  **What a Chilean clinic's CSV actually looks like decides two rules.** Excel in es-CL writes `;`
  between the cells and a decimal comma inside the coordinates — `-33,5211` — because the comma is
  this locale's decimal separator. A comma-only, dot-decimal reader mis-parses every one of those
  files, so the delimiter is read off the header line and a comma in a coordinate cell is a decimal
  point. And encoding is decided rather than guessed: bytes that are not valid UTF-8 are read as
  Windows-1252, which is what Excel writes when it is not asked for UTF-8. That is a rule and not a
  heuristic — a 1252 file with any accent in it is invalid UTF-8, and a valid UTF-8 file is never
  touched — where `mb_detect_encoding` would answer the same question by guessing.

  **The columns are a fixed allow-list, and that is a guard rather than tidiness.** They are the
  Spanish headings `CsvWriter` already emits, because those are the words the people using this app
  see; a column this reader does not know is ignored, since a spreadsheet always carries a code or a
  total or a note to whoever filled it in. Passing the unknown ones through instead would let a
  heading that happens to slug to `uid` or `source` reach into the DERIVED external id, and the same
  file saved with one more column would import as a second set of records rather than updating the
  first (ADR-0003). There is no `website` field on a Feature, so a column named that is ignored like
  any other. A row with no coordinates imports as a record with no geometry, which is a state the
  catastro is full of; a header with no «Nombre» is refused, in Spanish, naming the columns that are
  read.

  **The «Categoría» and «Subcategoría» CELLS are folded, not just the headings.** A CSV is the one
  format where a person types the classification, and what they type is what the Capas panel shows
  them — «Salud», «CESFAM», «Educación», «Organización social». `classify()` looks the pair up in an
  index keyed by slug, so those cells passed through raw matched nothing and every row landed in Sin
  clasificar silently, with no count that could explain it; only an already-lowercase, unaccented
  slug worked, which is not what anybody types. They now go through the same
  `TaxonomyService::slugFor()` the headings are matched on — slugified, not matched, for the reason
  `KmlReader::folderOf()` gives about the Folder it reads: which Categories exist is the import's
  business, not the reader's. It is a fold and nothing more, so «Deporte y recreación» still misses
  the seed's `deporte_recreacion` and falls to Sin clasificar, which is where it fell before;
  matching on a Category's label belongs beside `classify()`'s other two heuristics and in its own
  issue. The fixtures were the reason nothing caught it: every one of them, the browser gate's file
  included, was already pre-slugged, so the test that claimed to prove the pair files a record
  proved it only for input the implementation already handled.

  **It does not read this app's own CSV export back in, and the dialog says so before the import
  runs.** That export is lossy on purpose and in one direction only (issue #17): no id column, a
  «Clasificación» that is one label rather than two slugs, and contact cells carrying the leading
  apostrophe that stops a spreadsheet evaluating them. GeoJSON is the format that comes back in.
  The notice appears when a `.csv` is chosen, next to the KML one and for the same reason — a person
  who expects a round trip needs to know while they can still choose a different file.

  Two lines were written for this and then deleted, because nothing could be made to fail without
  them: a BOM strip, since headings are matched on their slug and `TaxonomyService::slugFor()` folds
  the marker out with the spaces and the accents, and a blank-line filter, since a row with nothing
  in the columns this reader reads is already dropped one function later. Both deletions are
  recorded where the line stood. A guard no test can fail on is a guard nobody can trust, and this
  repository has shipped one before.

## [0.67.0] — unreleased

An export can be a KMZ, which is the KML this app already writes, in an archive.

### Added

- **KMZ joins the export formats** (#102). The archive Google Earth prefers and the one people
  email each other: a KMZ is a ZIP holding a single `doc.kml`, so this adds no writer. The arm in
  `ExportService`'s match zips what `KmlWriter` already produces, and the criterion is that the two
  are byte-identical — a second rendering of the same records would be a second thing to keep in
  step with the first, and the drift would be invisible until somebody opened both.

  **No dependency.** `ZipArchive` is PHP's own and is in the runtime image already. It cannot write
  to memory, though: `close()` is what flushes an archive, and it flushes it to a path. So the file
  is built in the temporary directory and read back, and the KML is rendered *into* the already-open
  archive rather than before it — which is what makes a write that fails halfway a path the
  `finally` covers rather than a case nothing reaches.

  **The privacy rule extended with the format, which is the part that was not free.** `ExportService`
  refuses a KML on the confirmed route because a KML carries no contact details whatever is asked
  for, and a password prompt over a file with nothing personal in it is the unearned prompt ADR-0006
  exists to avoid. A KMZ is a KML, so the same refusal covers it, and the four places in the export
  dialog that compared `format !== 'kml'` now ask one `kmlFamily` instead. Left drifting, a KMZ with
  contacts ticked would have asked for the person's password, been refused by the server afterwards,
  and — worse — written an entry to the export log saying contact data left the building when none
  did. That log is the one record of who took personal data out (ADR-0012); a false line in it is
  costlier than a missing feature.

  **Not asserted: that Google Earth opens it.** Nothing here has Google Earth in it. What is checked
  is that the archive is a ZIP, that its only entry is named `doc.kml`, and that those bytes are the
  KML export's bytes — the format's whole definition, and as far as a check on this machine can
  honestly go.

## [0.66.0] — unreleased

The assignment tests stop describing the instance they happen to run on, and the awkward case they
have to survive is arranged by the test rather than waited for.

### Fixed

- **`AssignmentTest` stops asserting things about the whole instance** (#117, #121). Redrawing a
  Sector east leaves the Place it no longer covers pointing somewhere else, and the history
  assertion said that somewhere was `null` — which is true only while *no other* boundary on the
  install covers the coordinate. Fifteen lines above it, the in-flight assertion already refused to
  make that claim and said why: this suite runs against an instance the browser gate and every other
  suite also write to. So `make test-integration` went red on `main`, with an empty working tree,
  because a browser-gate run that died partway through (an `appinfo/info.xml` bump put the instance
  into `needsDbUpgrade`) never retired its own four-kilometre Sector, and that Sector claimed the
  fixture coordinate. It is the third time leftovers have taken these tests down — #58 and #60 were
  the first two — and every remaining slice's lander runs this suite, so a test that fails for
  reasons unrelated to the change is how a real failure gets waved through.

  The assertion now says what the redraw promises and nothing more: a `sectorId` change is recorded,
  its `from` is the Sector under test, and its `to` is *not* that Sector. Where the record landed
  afterwards is a fact about the Registry, not about the redraw.

  **The awkward case is arranged rather than waited for.** The test now draws a second Sector over
  its own fixture coordinate before drawing the one it moves, so "somebody else's boundary covers
  this point" holds on every run instead of on the unlucky one — the condition the assertion has to
  survive is part of the test rather than a property of whichever instance it happens to meet. It is
  retired through `FeatureService` at the end rather than left to the raw-SQL teardown, because
  retiring a boundary releases the records it claimed (#30) and deleting one leaves them pointing at
  an id no read resolves.

  Two siblings in the same file had the same shape and got the same treatment: the Place put outside
  a Sector, and the "moved out" half of `testMovingAPlaceReassignsIt`, both of which asserted `null`
  about a coordinate the install could legitimately have covered. The no-coordinate test keeps its
  `null` — a record with no anchor is inside nothing, whatever else exists — and
  `RevertAssignmentTest` was read and is sound: it already asserts the invariant rather than a
  restored value, and a comment there records the failure that taught it.

- **The `null` worth keeping moved to where the whole Registry fits in the test** (#117).
  "A record outside every boundary is assigned to nothing" is a claim about a whole install, so it
  is now made in `tests/unit/Service/AssignmentBoundaryTest.php`, where the Registry is one row the
  test wrote. Both halves are asserted there, the point inside resolving to that boundary and the
  point outside to nothing, because a `boundaries()` that found nothing at all would make the `null`
  pass for a reason that has nothing to do with the point.

  That trap had a second floor, found in review: the Registry held no Unidad Vecinal at all, so
  `assertNull($outside->getUnidadVecinalId())` held because there was nothing that *could* have
  claimed the point. It now holds a Unidad Vecinal three degrees east, and a third Place inside that
  one proves the division answers — so the null says "no Unidad Vecinal contains this point" rather
  than "this fixture has no Unidad Vecinales". Both halves were watched failing, by returning the
  first boundary from `containing()` without asking where the point is.

### Notes

- **Tests only.** `lib/`, `src/` and `js/` are untouched — the defect was in what a test claimed,
  not in what the app does — so no bundle ships with this version; `git diff --stat` against `main`
  is the check, not the assumption.

- **The rival is what makes the case reproducible, and that was measured.** On the dev instance as
  it stands today no live boundary covers the fixture coordinate (the leftovers #121 named were
  retired when it closed), so the old `assertNull` *passes* there: the defect #117 reported is
  invisible until the instance drifts again. With the rival drawn by the test, the old assertion
  fails on every run — `Failed asserting that 17661 is null`, the same shape #117 reported — and
  the new one passes. That is the difference between a criterion the suite exercises and one that
  waits for an unlucky day.

## [0.65.0] — unreleased

The umbrella closes: the Category tree is editable data, and the design doc stops saying it is not.

### Documentation

- **`docs/design/map-legibility.md` § 5 loses its first item** (#36, #40). It said *the taxonomy is
  not editable* — no taxonomy route in `appinfo/routes.php`, no UI, the eleven Categories seeded and
  in practice fixed — and that this was why a palette could be specified at all and expected to
  survive. Every sentence of it is now false: six taxonomy routes, a Capas panel that adds, renames
  and removes both a Category and a Subcategory, and eight vetted spare colours. Its own closing
  sentence predicted that taxonomy editing would need a rule for what colour a new Category gets;
  that rule is #43 and ADR-0017.

  This is the last of #36's eight acceptance criteria and the only one no slice of #41–#47 could
  close on its own. CONTEXT.md has called a Category "editable data, not code: a clinic adds its own
  and it appears in the panel and in reports at once" since the glossary was written, and the note
  existed to say out loud that it was aspirational. With the note gone the glossary is a description
  again, which is the point of the criterion.

  § 5 keeps its second item and becomes *One thing…*. **A Sector still has no team** — nothing in
  `Feature.php` or the schema carries one — so the surviving item was checked against the code
  before it was kept rather than kept because it was already there.

- **The ramp sections say what #43 and #45 changed** (#36). The palette specification's premise was
  the claim removed above: § 1.2 could enumerate eleven colours because eleven was all there would
  ever be. It now says the table is the palette a clinic *starts* from; that eight vetted spares
  ship beside it as `EnsureSeedData::SPARES`, derived by `ramp-check.py`'s own functions inside the
  six existing hue families; and that the check re-gates all eight on every `make lint` against the
  eleven, both neutrals and each other — so editing a Category's colour is now also a statement
  about colours no Category holds yet.

  The paragraph that says **there is no room for a fourth neutral** gains the case that tests it and
  does not break it: a Category added once the eight spares are gone reuses the Sector default
  `#949494` rather than earning a fourth grey, and deliberately not `UNKNOWN_COLOUR`, which means a
  broken reference. § 1.1 says where a clinic-added Category's pictogram comes from — the same Maki
  catalogue, all 215 layouts, previewed at the 19px the pin draws — and § 6 records that the ramp
  check clears the spares as well as the eleven.

- **A second claim in the same document, with the same premise, was false too** (#36). § 1.1 said
  `webpack.config.js` overrides the inherited `.svg` rule with `asset/source` for
  `node_modules/@mapbox/maki`. It has not since #46: that rule existed because eleven icons were
  imported one file at a time, and a picker offering all 215 cannot be built that way, so
  `src/glyphs.js` reads Maki's own browser bundle and the package needs no webpack rule at all. The
  eleven could be enumerated as imports for exactly the reason the palette could be enumerated as a
  table — eleven was all there would ever be — so closing the umbrella meant checking the paragraphs
  that premise reached, not only the one that stated it. Found by opening `webpack.config.js`.

### Notes

- **Nothing outside `docs/` changed.** `src/` is untouched, so `js/` is not rebuilt and no bundle
  ships with this slice; `git diff --stat` against `main` is the check, not the assumption.

- **#40 closes with #36.** It is the spec behind the umbrella rather than a build item, and it
  carries no acceptance criteria of its own — its first lines say #36 "stays the umbrella and keeps
  its acceptance criteria". Everything it specified now exists.

- **#36's eight criteria were closed against named mechanisms.** Each one is answered by a test in
  `tests/integration/CategoryCrudTest.php`, a check in `tools/browser-check.py`, or `tools/ramp-check.py`
  under `make lint` — never by reading the code and finding it convincing. The verdicts are on the
  issue.

## [0.64.0] — unreleased

The pictogram that identifies a Category is the clinic's to choose.

### Added

- **A Category's pictogram is the clinic's to choose** (#46). The other half of ADR-0017, and the
  half a person can actually answer: no software guesses which mark means «comité de vivienda»,
  while nobody satisfies a colour's constraints by eye. The swatch in each Capas row is now the
  control — clicking it opens a grid of every mark the app vendors, and the mark is picked while a
  new Category is being named as well as changed on one that already exists. The choice is stored
  against the Category and drawn wherever the seeded eleven are: on the map pin, in the Datos list
  and in the Capas legend.

  **Previewed on the pin, not on a swatch.** Each mark in the grid is drawn as the map draws it —
  a 19px pictogram on a 26px dot in the Category's own colour. ADR-0017 asked for that from the
  start and #37 is why it is not a detail: the seeded eleven were first picked by rendering them
  side by side at a desk, and three of them had to be re-picked once they were seen on a busy
  basemap. A mark that reads at the legend's 11px can dissolve at the size it is used.

  Making the swatch a control is also what nearly cost the size: a `<button>` takes the server
  stylesheet's 34px clickable area, which beats `height: 16px`, so the legend's circle drew as an
  ellipse and every mark in the grid sat on a 26×34 dot — the one size ADR-0017 says it must not
  be. Nothing but a browser could see it (jsdom has no server stylesheet), and nothing did until
  the gate ran against a built bundle.

  That number has therefore moved once already, so it now lives in one place. `PIN_DOT_SIZE` and
  `PIN_GLYPH_SIZE` are in `src/marks.js` and the pin reads them through custom properties the
  Leaflet `divIcon` writes onto its own markup; the stylesheet no longer carries a copy. The map
  had already written 26 twice — once as `iconSize` in JavaScript and once as a `width` in CSS —
  and the picker would have made a third copy in a third file.

  **The catalogue is the package, not a list.** `src/glyphs.js` used to import eleven icons one by
  one, which is the seeded set and not something to choose from. It now reads Maki's own browser
  bundle, which ships every name and its markup — no new dependency, and `webpack.config.js` loses
  the `asset/source` rule it carried for the single-file imports. Deliberately not
  `require.context()`: that helper is webpack's alone, so under vitest the catalogue would have
  been empty and every assertion about a chosen mark would have passed while proving nothing.

  **A stored mark overrides the seeded map; it does not replace it.** The eleven stay keyed by slug
  in `src/glyphs.js` and no row carries a copy of them, so an install where nobody has chosen
  anything draws exactly what #37 re-picked, and a clinic that renames «Salud» does not have to
  re-pick a hospital. `glyphFor()` answers with the stored name, then the slug's mark, then the
  neutral — never nothing. The last of those three is not defensive: `glyph` is a VARCHAR on a
  route open to every user (ADR-0005), and a restored dump or a retired Maki icon can put a name
  there that this app cannot draw. It degrades to the state the Category was already in, which is
  what ADR-0017 says must read as «unclassified», rather than to a blank that reads as a rendering
  failure.

  **The grid holds the focus, all of it and not only its cells.** The field a new Category is named
  in cancels the whole add on blur (#45), so the picker carries `@mousedown.prevent` — on its root,
  where it also covers the title, the padding and the gaps between the 215 wrapped circles. Guarded
  per cell it looked right and was not: a wrapped row of 26px circles is mostly gap, and landing on
  one threw away both the typed name and the mark already picked, silently. The browser gate now
  clicks the grid's own title and watches the name survive, because a check that only clicks a cell
  dead centre cannot tell the two versions apart.

  The name's SHAPE is checked on the write path and its existence is not. The inventory belongs to
  `@mapbox/maki` and lives in the frontend; a second copy of 215 names in PHP is exactly the drift
  `nextColour()` refuses to introduce for colours. A character class is still a description of that
  inventory, though, and the first one was narrower than the inventory it described: it refused a
  capital, and thirteen of Maki's 215 are the `-JP` variants — `bank-JP`, `hospital-JP`,
  `school-JP` — so the grid offered thirteen marks the write path answered «Ese pictograma no
  existe en esta aplicación.» for, about marks the app itself had put there. `glyphs.test.js` now
  reads the class back out of `TaxonomyService.php` and fails when the two sides disagree, which is
  the check that would have caught it and the one a copied regex in a test could not.

  Both edits travel on the PUT that already existed, which is why `category#rename` is now
  `category#update` — a method called `rename` that also sets a pictogram is a lie. It acts on the
  body key that is *present*, not on the one that is non-empty: an empty `glyph` is how a choice is
  cleared, and reading that as «no glyph was sent» refused the write with «El nombre no puede
  quedar vacío.» about a name nobody was typing.

## [0.63.0] — unreleased

A layer the clinic does not use can go — and cannot go while it holds records.

### Added

- **A Category or a Subcategory can be removed, and is refused while it holds records** (#47). The
  bin beside the pencil takes away a layer a clinic does not use. It asks first, in the row itself
  — the same two-step inline shape retiring a record already uses, and never a browser `confirm()`,
  which blocks the page. The row leaves the Capas panel and the Subcategoría picker at once, with
  no reload, because the server answers with the whole tree and the browser swaps its copy for it,
  exactly as a rename and an add already do. A removed Category takes its Subcategories with it:
  they carry its id, nothing in the schema would catch the orphan, and left behind they would
  vanish from every screen while staying in the table forever.

  **While anything is filed under it, the removal is refused** (ADR-0016). Two other options were
  weighed there and rejected: moving the records to «Sin clasificar» is a silent bulk
  reclassification triggered by a click somebody meant as tidying, and retiring them alongside
  hides «retire 340 places» behind a button that says «remove this layer». Refusing is the only one
  where the size of the consequence is visible before it happens — and it keeps ADR-0005's promise
  that nothing in the Registry is destroyed by never putting it to the test. So there is no
  `?force`: a flag re-offering the retire-alongside option would be that decision put back as a
  query parameter.

  **The count is a new query, and it is not the one Análisis shows.** Análisis counts what a person
  is looking at — filters applied, retired records and boundary Datasets excluded. The refusal has
  to count what would be *orphaned*, retired records included, because a retired record can be
  restored and the schema carries no foreign key from a Feature to its Subcategory that would catch
  the orphan afterwards. Two numbers answering two questions; conflating them is exactly how the
  orphan gets through, so `FeatureMapper::countBySubcategory()` is unfiltered and
  `findAll()` — which is the display read — is deliberately not reused. The integration suite
  measures both numbers against the same Subcategory in one test, and the refusal *says* the
  retired ones are counted: a person who filters Capas to that Category and counts nine against a
  refusal that said twelve has been handed a contradiction.

  **The refusal is a next step rather than a dead end.** It names how many records there are and
  where to see them — «deje visible solo «X» en Capas y revise «Ver retirados» en Datos» — because
  «84 registros» on its own leaves somebody stuck with a number.

  **«División territorial» can never be removed, and neither can Sector or Unidad Vecinal.** A
  Sector and a Unidad Vecinal cannot be saved without one of those Subcategories and the seed
  restores them on every upgrade, so a removal would be undone by the next `occ upgrade` with no
  refusal anywhere. The panel says so where the control would otherwise be — in the toggles block
  beside the tree, which is where CONTEXT.md puts a territorial division — rather than at save
  time, so nobody spends time trying and then wonders whether they did it wrong. The server refuses
  it too, and that guard runs *before* the count: an empty division would pass the in-use one.

  The server's rule names the two slugs the seed actually ships, read out of `EnsureSeedData` rather
  than repeated, and not "anything under the division". The reason those two cannot go is that the
  seed puts them back; a third row somebody posts under it by hand — which the add route still
  accepts, deliberately, as #44 recorded — is restored by nothing, so refusing to remove that one
  too would only make a mistake permanent.

  **«Sin clasificar · Otro» can never be removed either, and for a harder reason.** It is not a
  layer a clinic chose, it is where the app puts what it cannot classify: an import resolves that
  exact pair on every run and refuses the whole file without it, a brand-new record starts there
  rather than at whatever Category sorts first — «Comercio y economía · Feria», the silent
  misfiling the explicit lookup exists to prevent — and `tools/boundary-features.php` stamps it on
  every Limit it fetches. Being EMPTY is its ordinary state on a clinic whose imports resolve
  cleanly, so the in-use guard would have waved it through on exactly the installs that depend on
  it, and the seed would have put it back on the next `occ upgrade`: imports broken this morning
  and working after the upgrade, with no refusal anywhere to say what happened. The pair is now
  named once, in the seed that creates it (`EnsureSeedData::FALLBACK`), and both the import and the
  refusal read it from there. The Subcategory is refused as well as the Category: the
  last-Subcategory rule below happens to cover today's single «Otro», but only for as long as it is
  the only row under it, and a second one can be added.

  **A Category cannot be emptied of its last Subcategory either.** Not one of #47's criteria, and
  in because removal is the only path that can reach a state CONTEXT.md says does not exist: a
  Category always carries at least an «Otro», and one with none is a branch in the Capas panel that
  reaches neither the Subcategoría picker nor Análisis, with nothing filable under it. The refusal
  says to remove the whole Category instead — not a cascade into doing that, for ADR-0016's own
  reason about the option it rejected: «quitar esta subcategoría» and «quitar toda la categoría»
  are not the same sentence and must not be the same button. It is the *last* of the three
  refusals, after the count: a Category added by a clinic arrives with exactly its «Otro», so one
  Subcategory holding records is the ordinary case, and refused for being the last one it would
  answer with no number and no pointer — and the way out it names, removing the whole Category, is
  itself refused for those same records. Two refusals to reach the sentence ADR-0016 asks for is
  the dead end that ADR exists to prevent.

  The browser's own filter is pruned on the way. It records what is HIDDEN, which is what makes a
  row added to the taxonomy appear on its own — and leaves a row removed from it as an id naming
  nothing, still counted by the «N ocultas» badge with «Ver todo» offering to reveal it.

  Like a rename and an add, a removal does not advance the Revision cursor (ADR-0002's counter
  hangs on a Feature id and a Category is not a Feature), so a colleague's open browser still lists
  the removed row until it reloads. Nothing can be filed under it there either, so what they see is
  a stale panel rather than a record going astray.

## [0.62.0] — unreleased

A Category is the clinic's to add, and the app is the one that picks its colour.

### Added

- **A Category can be added from the Capas panel** (#45). «Agregar categoría» under the tree opens
  a field; Enter posts the name and the Category is a branch of its own, in the Subcategoría picker
  and — once something is filed under it — in Análisis, without a reload. It is the last of the
  three things CONTEXT.md has always claimed a clinic can do to its own taxonomy and the app could
  not.

  **The person types a name and nothing else.** The slug is derived and globally unique, like a
  Subcategory's (#40). The colour is *assigned*: the first entry of the spare list #43 shipped that
  no Category already holds. That is the whole of the rule — ADR-0017: «assigning a colour becomes
  taking the next unused spare» — and it is deliberately not a computation. A colour has to clear
  3:1 against a white page and against the dark theme, stay ΔE-OK 0.05 from every other Category
  and both neutrals, and 0.015 from each of them under three dichromacies; that arithmetic lives
  once, in `tools/ramp-check.py`, which derives the spares and re-checks every one of them on
  `make lint`. A second implementation in PHP on the write path is exactly the drift the check
  exists to prevent, so there is none — and there is no colour input for a Category, here or
  anywhere else. Whoever wanted a particular colour gets one that works instead of one they chose.

  Nothing new is stored to remember which spares are spent. The answer is derived from the
  Categories that exist, so two adds in a row cannot collide — the first is a row by the time the
  second looks — and a Category removed (#47) will hand its spare back without anybody writing the
  code to do it.

  **Eight spares is a real ceiling, and reaching it is not a failure.** The ninth clinic-added
  Category is still created, still works, and takes the neutral. The panel says so, in an `info`
  note card and never an error: ADR-0013 holds that hue only groups Categories into families and
  the pictogram is what identifies them, so a Category without a distinct hue is doing what that
  ADR says every Category does anyway. `color === DEFAULT_SECTOR_COLOUR` is the entire signal —
  no flag travels on the response, because a flag is a second description of the same fact and can
  disagree with the colour it describes.

  That neutral is now written down twice, once in PHP for the write path and once in
  `src/taxonomy.js` for the panel that compares against it, in two languages that cannot read each
  other. `make lint` holds the two values equal. Edited apart, they would leave a Category that
  looks neutral and is never explained, with every suite green — nothing on either side holds both
  files open.

  **A new Category arrives with an «Otro» Subcategory**, as all eleven the seed ships carry. A
  Feature travels as a Subcategory id, the picker is a flat list of «Categoría · Subcategoría» and
  Análisis counts records — so a Category with no Subcategory would be a branch with nothing in it
  that reached neither of the other two surfaces, and nothing could be filed under it at all. The
  clinic narrows it with #44's «+» whenever it wants to.

  It draws a pictogram rather than a blank, because the glyph lookup already falls back to the
  neutral mark for a slug it does not know — a blank reads as a rendering failure where the neutral
  reads as unclassified. Choosing a mark of its own is #46.

  Uniqueness is `terr_cat_slug_idx`'s job, not the app's, for the reason #44 gives about its own
  index: a check-then-insert is a second copy of the rule that two people adding «Comité de
  vivienda» at the same moment would both pass. The violation is caught and turned into a sentence
  — «Ya existe una categoría con ese nombre.» — said of the name rather than of the slug, because
  the slug is derived and naming it would describe something nobody typed. A blank name, and one
  that derives to no slug at all, are refused the same way.

  Nothing removes a Category yet (#47), so the browser gate's Category is named after the run and
  stays behind like #44's Subcategory — but unlike a Subcategory it *spends a spare colour*, so the
  dev instance walks down the list over time. The gate derives the colour it expects rather than
  naming one, which keeps it honest as that happens and means the exhausted-spares branch starts
  being exercised for real rather than only in the integration suite.

  It does not tell the browsers that are already open, for the reason and at the cost `App.vue`
  records on the Subcategory add path.

## [0.61.0] — unreleased

The clinic can put a word of its own into the taxonomy, not only re-word the seed's.

### Added

- **A Subcategory can be added under a Category from the Capas panel** (#44). The first thing the
  clinic can put into its own taxonomy rather than only re-word. A «+» beside each Category's
  pencil opens a field at the top of that branch; Enter posts the name and the new Subcategory is
  in the panel, in the Subcategoría picker and — once something is filed under it — in Análisis,
  without a reload. It is the thin create path on purpose: a Subcategory has no colour and no
  pictogram of its own, because it is drawn with its parent Category's (ADR-0013), so the one thing
  anybody types is a name.

  The slug is derived from that name and never typed (#40). It is the identifier every record is
  filed under, and a person who could type one could file records under `otro` twice over. The
  derivation is its own pure function with a unit test, unlike everything else on this write path:
  it is the only rule here with no database in it, and the only one that no screen would show going
  wrong.

  Uniqueness is left to `terr_subcat_slug_idx` — `(category_id, slug)` — rather than restated as a
  check-then-insert, which is a second copy of the rule that two people adding «Taller» at the same
  moment would both pass. What the app does is catch the violation and turn it into a sentence:
  «Ya existe una subcategoría con ese nombre en esta categoría.» The same name under a *different*
  Category is accepted, which is why the index is scoped that way at all — «Otro» is in nearly
  every Category and means something different in each. A name that is blank, or that derives to no
  slug at all («»»), is refused the same way, in the panel where it was typed.

  Every Category the panel draws as a branch gets a «+», which is every Category except
  `division_territorial`: that one is drawn as the Sector and Unidad Vecinal toggles above the tree,
  not as a branch, and a territorial division is drawn on the map rather than typed into a field.
  The exclusion is deliberate and is now said so in the panel; the route itself still accepts that
  Category, because narrowing it would be a decision this slice was not asked to make.

  Nothing removes a Subcategory yet (#47), which is why the browser gate names the one it adds
  after the run: a fixed name would be added once and refused as a duplicate by every run
  afterwards, and a check that only works the first time is not a check.

  It does not tell the browsers that are already open. Adding does not advance the Revision cursor,
  for the same reason a rename does not (the dated correction on ADR-0002: the counter hangs on a
  Feature id, and a Subcategory is not a Feature) — but the consequence is not the rename's. A
  colleague's tab has no entry for the new Subcategory at all, so the first record filed under it
  arrives through the change feed within seconds and reads there as «Clasificación desconocida», in
  the colour this app uses to mean a broken reference, until that tab is reloaded. That is accepted
  for the thin create path and marked where it is decided, in `App.vue`: pushing the taxonomy into
  open tabs wants a GET on it and a refetch from the poll, which is a slice of its own and not a
  corner of this one.

## [0.60.0] — unreleased

The Capas panel is the clinic's vocabulary now, not the seed's.

### Added

- **A Category and a Subcategory can be renamed from the Capas panel** (#41). CONTEXT.md has always
  said a Category is "editable data, not code"; it was not. The eleven Categories and their
  Subcategories were written once by the seed and there was no route, no screen and no `occ` command
  that could change a word of them — so a panel full of somebody else's names was something a clinic
  had to live with.

  Only the label moves. The slug stays exactly as it was, which is the whole point of doing this
  first: every Feature is filed under a Subcategory id, and every glyph and colour lookup keys on a
  slug, so a rename cannot put a record anywhere it was not already. An integration test renames
  both and reads them back through the served tree to prove the slugs and the ids did not move with
  the label.

  A rename also survives an upgrade. `EnsureSeedData` already promised that — it seeds the taxonomy
  only into an empty table and ensures «División territorial» slug by slug — but nothing had ever
  run the promise, so the repair step is now driven in-process by a test that renames first,
  including the structural Category the seed touches on every single upgrade.

  An empty or whitespace-only name is refused with a message saying so, and the message appears in
  the panel where the person typed rather than in `error`, which renders in the map column and only
  while no record is open. An over-long one is refused the same way instead of arriving as the
  database's own error through a 500: the column is `VARCHAR(128)` and the field says so. The
  message goes away again when a rename is opened or abandoned: the note card names no row, so one
  left over from a rename nobody is doing any more would read as a refusal of the one being typed.

  Renaming does **not** advance the Revision cursor, which contradicts ADR-0002 as written; the
  reasoning is a dated correction appended to that ADR rather than a new one. The browser that made
  the change swaps in the tree the server answers with — one property that the Capas panel, the
  Subcategoría picker, Análisis and the map's colours all read, which is what makes the new name
  appear everywhere without a reload — and another browser reads it on its next load.

## [0.59.0] — unreleased

A Category a clinic adds has a colour waiting for it, and lint holds the palette to the
shape those colours were derived under.

### Added

- **The ramp check derives the colours a new Category can be given, and the seed ships them**
  (#43). ADR-0017 has the app assign a Category's colour rather than ask a person for one: the
  constraints are 3:1 against a white page and against the dark theme, ΔE-OK 0.05 from every
  Category and from both neutrals, and 0.015 from each of them under all three dichromacies, and
  nobody satisfies that by eye. `tools/ramp-check.py` already implements exactly that arithmetic,
  so it gained `derive_spares()`: at the chroma ADR-0013 fixes (0.090), inside the six hue families
  it allows — read off the seeded ramp rather than listed, so repainting a Category moves its
  family with it — it walks lightness and keeps
  every value that clears all of it against the eleven, both neutrals, and the spares already
  taken. Eight, matching the ceiling recorded in #40 and ADR-0017. They ship as
  `EnsureSeedData::SPARES`, which is data the app can read rather than a number in a comment.

  Deriving here rather than on the PHP write path is the point: computing a colour when a Category
  is created means a second implementation of OKLab, contrast and three dichromacy transforms, in
  another language, and two implementations of one rule drift apart silently. Assigning a colour
  becomes taking the next unused spare, with no colour maths on the write path at all.

  `make lint` re-checks every shipped spare on every run — contrast, separation, dichromacy, chroma
  not raised, hue still inside a family — and gates the two things the derivation assumes about the
  palette it reads: that there are six hue families and not a seventh, and that ADR-0013's three
  greys are still grey. A tinted neutral is a hue family with no decision behind it, and the
  derivation would offer spares inside it. So moving a seeded colour to within ΔE 0.05 of a spare
  turns the build red at the moment the edit is made rather than the first time a clinic adds a
  Category. Editing a Category's colour is now also a statement about colours no Category holds
  yet. The number eight is the search grid's as much as the palette's: lightness steps by 0.005,
  and a finer step finds more only by packing them onto the ΔE 0.05 floor itself, which is the
  distance at which two dots start reading as one colour rather than a place to sit a palette.

## [0.58.0] — unreleased

The browser gate stops grading the app against the seed.

### Changed

- **The browser gate's colour check reads the taxonomy the app serves, not the seed** (#42).
  Nothing user-visible; a prefactor. `tools/browser-check.py` compared every Category swatch the
  browser painted against the colours parsed out of `EnsureSeedData`, in both directions — a seeded
  colour never painted, and a painted colour the seed does not carry. That is correct only while the
  seed is the only source of Categories. A clinic can already recolour one, and the moment it can add
  one, every clinic-added Category becomes an "unexpected" colour and the gate goes red on a false
  positive, permanently — and a gate that is permanently red is a gate everybody learns to ignore.

  It now reads the taxonomy out of the initial state the page was rendered with — the
  `#initial-state-territorio-taxonomy` element `@nextcloud/initial-state` reads — because that is
  what Vue received, and nothing serves it over HTTP, so a route invented for a check would have
  been a path the app never takes. Three checks read it rather than one: the Capas colour check, the
  Salud fill on a map pin, and the two bands of a cluster ring. The «División territorial» Category
  is dropped from the mapping, exactly as `treeOf()` drops it from the tree — its `#334155` is inert
  (#29), so it reaches no swatch and would otherwise be reported missing on every single run.

  What the check asserts is unchanged: every Category reaches the browser, and reaches it with its
  own colour. What it stops asserting is that the ramp on screen is the ramp the seed ships — and
  that is deliberate, because it is no longer the browser's business. `tools/ramp-check.py`, which
  this does not touch, still measures the seeded ramp's contrast and separation, and
  `tests/integration/RampRepaintTest.php` still proves a seed change reaches an install that already
  exists.

## [0.57.0] — unreleased

A cluster's colour means a Category again, and the map finally says how well it knows where a
record is.

### Changed

- **A cluster is coloured by the Categories under it, never by how many records it holds** (#22).
  `leaflet.markercluster` buckets purely on `getChildCount()` — green under 10, yellow under 100,
  orange at 100+ — so on the same map green meant «Deporte y recreación» at one zoom and "more than
  100 records" at the next. One visual channel carrying two unrelated meanings, switching as you
  scroll, and the one channel that groups Categories (ADR-0013) was the one being spent. The count
  was already written inside the bubble, so hue was not carrying it — it was duplicating it badly.

  The bubble is now a ring segmented by the Categories present, sized by how many records each
  holds, with the count in the middle in the theme's own ink. A cluster of one Category is one solid
  band, which is what a majority glyph could never have shown — that criterion is why the obvious
  design was ruled out. `MarkerCluster.Default.css` is no longer imported: it is, in its entirety,
  the three count buckets, so not importing it is what makes "no hue in the cluster means a number"
  true of the shipped bundle rather than of an intention. ~40 lines through `iconCreateFunction`,
  which markercluster was already offering and this app was already passing an options object to;
  `@kalisio/leaflet.donutcluster` was 459 lines and an undeclared `window._`, and was rejected.

- **An approximate coordinate draws a dashed ring** (#23). `locationQuality` was stored, validated,
  imported, exported and shown in the history — and drawn nowhere, so an `aproximada` record was
  pixel-identical to an `exacta` one and the map asserted a precision the Registry never claimed.
  For a team deciding where to visit, that is the difference between a street and a guess.

  The white ring already round every pin simply goes dashed, which is why this treatment won: it
  costs no footprint at all, and #37 was simultaneously arguing the pin is too small while La
  Florida holds ~865 places, so a halo or an edge badge spends space at exactly the wrong moment.
  Both were drawn and rejected. It is a dash and not an opacity because opacity is hue and alpha —
  the channel a dichromacy takes away and the one this map already overloads — and because dashed
  now means one thing across the whole app: *the app is not asserting this precisely*, exactly as a
  cadastre boundary the clinic did not draw does (ADR-0015). A cluster holding any approximate
  record inherits the same dashed ring.

  `sin_coordenada` gets no mark, because a record with no coordinate is not drawn at all and the
  Datos list already says «· sin coordenada». The `draftFrom()` coercion of `sin_coordenada` to
  `exacta` was revisited as #23 asked and **kept**, now with a comment saying why: the picker offers
  two options because Absent is derived and never chosen, `payload()` posts the whole draft, and a
  draft left holding `sin_coordenada` would post it alongside the first coordinate someone typed —
  which the server refuses outright — while the sidebar was showing «Exacta».

## [0.56.0] — unreleased

A territorial division renders as a division.

### Changed

- **A Unidad Vecinal is ground and a Sector is figure** (#21, #24, #29). CONTEXT.md separates a
  Cadastral Dataset — «the clinic reads it, never draws it» — from a Curated Dataset, «born inside
  the clinic». Enormous operational weight and, until now, zero visual weight: both were drawn with
  the same fill, the same outline and the same hover tooltip, differing only in hue. A Unidad
  Vecinal now draws with **no fill and a dashed `#7d7d7d` hairline**, and a Sector keeps a fill —
  now a 0.10 wash — and gains a **permanent label at its centroid**.

  **The label, not the colour, is what identifies a Sector.** CONTEXT.md says a clinic names its
  sectores «words, numbers or colours», so a colour-first identity is right for one naming scheme
  out of three and a label is right for all three. Colour is demoted to a wash that tells neighbours
  apart, neutral when nobody set one.

  **The `#949494` and the `0.10` are one decision, not two.** Composited over OSM land at the
  `fillOpacity: 0.15` that shipped, the worst dot-on-tint pair measures 2.90:1 — under WCAG
  1.4.11's 3:1 floor. At 0.10 it is 3.04:1. Changing either alone puts a measured contrast below
  its floor (`docs/design/map-legibility.md` § 2).

  **Label colour follows the tile and never the theme.** The basemap is raster OpenStreetMap under
  both themes, so a dark-theme label of `#ebebeb` over OSM land `#f2efe9` would render at 1.04:1 —
  invisible. The hexes in `TerritorioMap.vue` are literal on purpose. The tile pane itself is
  de-emphasised with one line of CSS; `saturate` rather than `brightness` because its matrix is
  luminance-preserving, so every contrast ratio measured against a tile still holds.

  **Unidad Vecinal labels wait for zoom 15**, held back by a class on the map container. Across the
  fifty to a hundred of a comuna they would be a sheet of text, and the alternative — the one
  Leaflet plugin that does label collision — monkey-patches `L.LayerGroup.prototype`, which is the
  exact invariant the layer swapping in `TerritorioMap.vue` protects.

  **Opening one from the list does not turn it into figure.** Opening any shape record copies its
  geometry onto the draft, which moves it from the shapes layer to the draft layer — and the draft
  layer styled everything on it as figure, reasoning that a draft is a shape the clinic is drawing
  right now. True of a new polygon, false of a cadastre boundary somebody merely clicked. The draft
  layer is now told what the drafted record is **filed as**, so a Unidad Vecinal keeps its hairline
  while it is open and only a brand-new drawing, filed as nothing yet, reads as figure.

  **An open division keeps its label.** The draft layer replaces the opened record on screen —
  `drawShapes` skips it — so anything `drawShapes` says about a record the draft layer has to say
  too. The style was carried across and the name was not, which made a clicked Sector the one
  Sector on the map with no name on it. It is bound from a `draft-name` prop, taken from the draft
  rather than looked up in the filtered set, for the reason the Subcategory is.

  **A side effect worth knowing:** with no fill, a Unidad Vecinal stops answering clicks across its
  whole area. Once a comuna's cadastre is loaded it tiles the entire view, so a click meant for the
  map beneath it now reaches the map. It is still selected from the list, and its outline is still
  clickable.

- **Only a Sector is offered a colour** (#24). The picker was gated on `isDivision`, true for any
  territorial division — an editing affordance on a record the domain says the clinic only reads,
  and one that made the figure-and-ground rule unguaranteeable, because `colourOf` honours a
  Feature's own colour over its Category's. **A Unidad Vecinal that already carries one keeps it**:
  nothing is cleared and there is no migration. It simply has nowhere left to render.

- **A territorial division draws a ring in the Datos list, not a dot** (#29). Every Unidad Vecinal,
  and every Sector nobody recoloured, was painted as an 18px `#334155` dot — **1.73:1 against the
  dark theme**, where WCAG 1.4.11 asks 3:1. There was no legible grey to move it to: ADR-0013
  measures the neutral band as full at ΔE 0.055 against a 0.05 floor, and every candidate outside it
  reads wrong for a boundary. So the division stops being a dot. The ring echoes the map — dashed
  for a Unidad Vecinal, solid for a Sector — and follows the **theme** rather than a data colour,
  which is what makes it legible in both. The glyph inside is dropped: a division has no pictogram,
  so it fell back to `circle-stroked` — an outlined circle inside an outlined ring.

  **Found and not fixed here: a Unidad Vecinal can still be reshaped and saved** (#109). Opening
  one hands its geometry to the draft layer, which enables geoman on whatever is on it, so a
  cadastre boundary the clinic only reads gets draggable vertices. It predates this work — the
  draft has always copied any opened shape's geometry — and it is a write-permission question
  rather than a figure-and-ground one, so it is its own issue and the code points at it. Half
  fixing it here, by hiding the handles while `save()` still accepts the reshape, would have
  removed the only thing currently disclosing that the write path exists.

  `#334155` stays in the seed as **inert data** and now says so in a comment. `colourOf` answers
  with the Sector neutral for a division with no colour of its own, `divisionsOf` stops carrying a
  copy nobody painted, and in the Análisis chart — the last surface it could still reach — the
  counts themselves carry the **resolved** colour rather than the stored one. Resolving it in the
  chart instead left two bindings to keep in step, and only the Category row was changed: the
  `Sector` and `Unidad Vecinal` bars under it went on painting the seed at opacity 0.55. `tools/ramp-check.py` drops its `KNOWN, issue #29`
  block and the helper that fed it: a division is not a dot, so its colour is out of that check's
  scope.

## [0.55.0] — unreleased

The map pin is big enough to read, and the glyphs beside a label are white at last.

### Changed

- **The map pin is 26px with a 19px glyph** (#37). It was 22/14, and 12px of mark was derived by
  rendering the eleven pictograms side by side at a desk — which says nothing about reading a pin
  on a busy basemap, at a glance, on whatever screen a clinic actually uses. From real use it came
  back as «not great or not easily visualised», and the report beats the measurement. Most of the
  four extra pixels go to the mark rather than to the footprint — the glyph is now 73% of the dot
  against 64% — because a bigger dot is also a dot that overlaps its neighbours sooner, and La
  Florida holds ~865 places against this instance's few hundred. The 2px white ring stays: it is
  what keeps a dot off fill-vs-tint contrast over a tinted Sector.

  **The Datos list (18/12) and the Capas legend (16/11) deliberately did not move.** The pin is the
  only mark with no label beside it; the other two have their name right there and only have to
  read as the same pictogram. "Scale them all together" is the tidy-looking change that undoes this.

- **All eleven Category pictograms were re-picked at pin size** (#37), still from Maki, and judged
  as rendered pixels at 19px rather than as vector art. `school` (a pencil crossed with an apple),
  `recycling` (three hairline arrows) and `residential-community` (a tower block beside a lamp post)
  became `college`, `park` and `town` — the cap, the tree and the houses a phone map already taught
  everyone to read. The other eight survived, three of them on purpose: `place-of-worship` because
  the app must not assume a denomination, `circle-stroked` because «Sin clasificar» must read as
  undecided and not as an error, and `pitch` over `soccer` because the list and the legend draw the
  same mark at 12px and 11px, where `soccer`'s ball detaches into a stray speck.

  **ADR-0017 is amended with this**, because it tells the picker #46 builds to preview a pictogram
  "at the size the map pin actually draws — 12px of mark is the floor". The pin draws 19px now, so
  a picker previewing at 12 would be choosing at a size nothing renders.

  **`police` is the weakest of the eleven and stayed anyway.** Maki ships no shield and no badge,
  and every alternative in the set means something narrower — a flame is bomberos, a warning
  triangle is danger — so it is flagged in #37 for the owner rather than swapped for a crisper mark
  that means the wrong thing.

### Fixed

- **The glyphs in the Datos list and the Capas legend were never white** (#37). They rendered in
  the theme's text colour, which is dark, on a coloured dot — and the comment in both files said
  the opposite. Not a missing rule: `@nextcloud/vue` ships `.icon-vue[data-v-…] svg { fill:
  currentColor }` at specificity (0,2,1), and a scoped `:deep(svg) { fill: #fff }` compiles to
  (0,2,1) as well, so the tie went to whichever stylesheet loaded last and the library won. The fix
  is to stop fighting the component — `NcIconSvgWrapper` is built to take `currentColor`, so the
  swatch sets `color: #fff` and both `:deep()` blocks are gone. No `!important`, no specificity
  war, and nothing to re-lose at the next `@nextcloud/vue` bump. The map pin was never affected: it
  injects raw SVG with no wrapper, so nothing competes there.

  It shipped for as long as it did because no test could see it. The browser gate now measures the
  painted fill of both, and the pin's dot, glyph and anchor with them — the anchor against the dot
  it has just measured, so «resized the dot, forgot `iconAnchor`», which puts every pin off its own
  coordinate, cannot pass by agreeing with a constant.

## [0.54.0] — unreleased

«Retirar del mapa» is where the hand reaches for it.

### Fixed

- **«Retirar del mapa» is reachable without scrolling** (#35). Reported from real use as "there is
  no button that allows deletion". There was one: `FeatureDetail` rendered *below* the Limit editor,
  so a Sector's suggestions, picked groups and limit sentence pushed every control of the form down
  and retiring, being the last of them, sat 721px past the bottom of a 620px screen. Nothing
  underneath was broken — the browser gate retired a Sector on every run — which is exactly why
  three suites and a gate stayed green while a person could not find the control.

  **Two halves, because reordering alone does not hold.** Mocked up at the measured viewport, the
  record-above-the-editor order clears the fold with a 4-tramo límite, is marginal at 16 and is back
  under it at 30, because the control is still the last thing in a component that grows. So Guardar
  and «Retirar del mapa» now sit in one action group at the top of the form, stuck there with
  `position: sticky` — the same shape `CapasPanel.vue` already uses. Sticky is what makes the
  position independent of the Limit's length, and it is the only version where a browser assertion
  about a viewport position cannot be quietly invalidated by a longer límite.

  **The group stays inside the `<form>`**, so Guardar is still a real `type="submit"` and the
  @nextcloud/vue 9 `native-type` trap is not reopened. Pairing a destructive button beside the
  primary one is safe *because* the two-step confirmation stays — which is also why it must not be
  dropped now that they sit together. Retiring is still not deleting: the confirmation still says
  the record keeps its history and can be restored (ADR-0005), and the header, an action menu and a
  fixed footer were all considered as homes and rejected.

  The browser gate now **measures** rather than clicks: `bounding_box()` against the viewport
  height, at 1366×768 and at the 1362×620 the issue was measured in, for a Sector carrying 30
  tramos, a Place, a Route and a Unidad Vecinal. It found two things the suites could not.

  **`position: sticky` is bounded by its containing block, and the containing block is the
  `<form>`.** The Limit editor was rendered beside `FeatureDetail`, not inside it, so the bar stuck
  only until the form's own height had scrolled past — and then left with it, measured at y=-420px
  on a 620px screen. The editor is slotted inside the form now, which is also where it belongs: the
  Limit it edits is part of the draft Guardar saves. Its search box gained
  `@keydown.enter.prevent`, because inside a form with a submit button Enter would otherwise save
  the record instead of doing what it did before, which is nothing.

  **`--color-error` is a background colour.** In this Nextcloud it is `#FFE7E7`, the pale tint an
  error banner is filled with; the readable foreground red is `--color-error-text` (`#8A0000`). The
  destructive outline was therefore painted at a contrast of 1.18:1 — near-white on white, invisible
  — and every assertion in the gate passed, because they all asked where the button was and none
  what it looked like. The gate now measures the WCAG contrast of the retire button's text and
  border against the bar, and that Guardar is filled while the retire button is not.

## [0.49.0] — unreleased

A Sector can be reached from the keyboard.

### Added

- **Every Zone and every Route on the map is now tab-focusable and opens on Enter** (#25). A Place
  pin always was: `L.Marker` defaults to `keyboard: true` and sets `tabIndex` and `role` on its own
  icon. `L.Path` has no keyboard option at all — neither `Path.js` nor `Layer.js` mentions one — so
  a Sector or a Unidad Vecinal could be selected with a mouse and by no other means. The shapes now
  carry `tabindex="0"`, `role="button"` and an `aria-label` holding the Feature's name, and Enter or
  Space does what a click does.

  **The same function, not a parallel one.** The click handler holds the `placing` guard that lets a
  click through to the map while a draft is waiting for its coordinate; a keydown that emitted
  `select` on its own would go round it and select a Sector at the one moment selecting one moves
  the open record. So the callback was extracted and both are bound to it.

  **Set after `addLayer`, on every redraw, and never unbound.** `_initPath` builds the SVG element
  only when the layer joins the map, so `getElement()` is null before that. And every redraw here
  builds a NEW group with NEW elements and drops the old one whole — the swap that keeps Leaflet
  from holding a layer whose map is gone — so a handler bound to an element dies with it and is
  never bound twice. There is deliberately no teardown: there is nothing that survives to tear down.

  **No plugin.** The Leaflet plugin directory was swept for this (docs/design/map-legibility.md D6);
  `Leaflet.a11y` covers popup focus and non-interactive marker labelling, neither of which applies.

  **Two shapes on the map still have no tab stop, both on purpose.** The record currently open in
  the editor is drawn by the geoman draft layer — `drawShapes` skips the feature being edited so
  nobody drags the vertices of one polygon while looking at another on top of it — and Enter on it
  would mean "open what is already open". And the streets `drawPickable` puts up while a Limit is
  being chosen are a different surface, present only inside the editor, and outside what #25 asks
  for; they are worth an issue of their own.

- **A focus ring on the map** (#25). `:focus-visible`, in the theme's own primary element colour and
  offset by two pixels, the same idiom the selected pin already uses — so it flips with the theme
  rather than being one fixed colour that works in one of them, and it never sits directly on a
  Sector's own stroke, which is a colour the clinic chose and could match.

### Changed

- **The zoom buttons are labelled in Spanish** (#25) — «Acercar» and «Alejar», through
  `L.control.zoom`'s own `zoomInTitle` / `zoomOutTitle`. They were Leaflet's English defaults, and
  they are the only text on the map a screen reader reads out of a control rather than out of a
  record.

## [0.48.0] — unreleased

A Limit says when the cadastre lost the line it cites.

### Added

- **A Limit part says when the cadastre no longer has the line it cites** (#51). Picking a street
  records `{featureId, name}` and the name is frozen on purpose, so the Limit still reads after a
  cadastre release. The id was not maintained at all: a retired or withdrawn line went on rendering
  as a live pick, indistinguishable from one still there. The part now shows «ya no está en el
  catastro» beside the existing «a mano», with a count when only part of a name went away — three
  of Vicuña Mackenna's thirty ways is three, not "Vicuña Mackenna is gone". The part keeps its name
  and stays in the Limit either way: a sentence a team says out loud does not change because a
  cadastre release did.

  **Derived on read, never stored.** A flag on the part would have to be swept onto every Sector
  citing the id at retire time — one Revision and one history entry apiece, on records nobody
  edited — and swept back on restore; and `FeatureService::limit()` rebuilds every part as
  `{featureId, name}` and drops anything else, so it would not survive the next save anyway.

  **But the browser cannot work it out alone**, which is why the server answers it. `/boundaries`
  excludes retired rows and loads only what is in view, so a cited id that does not come back means
  "off-screen **or** gone" — deriving it in the client would label half a comuna as missing from the
  cadastre every time somebody zoomed in. The editor now sends the ids its draft cites and the
  answer is `{features, gone}`.

  **`gone` is ids and nothing else** — never a name, a geometry, or a `retired` flag per row. The
  features array becomes one list in the client that feeds the autocomplete, the clickable layer on
  the map and the cache holding each picked line's shape, so a retired row arriving with a name and
  a shape would be pickable again and would un-grey «Generar», undoing ADR-0005's "retired records
  are never drawn".

  **«Gone» means retired** — by `Retirar del mapa`, or by reverting the import batch, which retires
  the same way. A re-import does *not* retire what a newer file stopped publishing: an import walks
  only the rows the file contains. Making it sweep the absent ones is a cadastre policy with its own
  consequences — a truncated Overpass download would retire the whole comuna — and it belongs to an
  issue of its own.

  **What it deliberately does not do is drop the line's shape.** `pickedGeometry` caches each picked
  line's geometry so «Generar» survives panning away from it, and a line retired by somebody else
  while the editor is open keeps its cached shape until the Sector is reopened. Clearing it would
  mean «las calles no cierran» about lines that do close — the exact failure that cache exists to
  prevent — and the part already says «ya no está en el catastro» on screen, so the person is told
  either way. The stale shape is per-session: `pickedGeometry` starts empty on every reopen.

## [0.47.0] — unreleased

A Sector says how its boundary came to be.

### Added

- **A Sector says whether its line was generated or drawn, and when it last moved** (#50). One
  sentence in the sidebar, at Sector level: «Línea dibujada a mano.», «Línea fijada junto con el
  límite el 05-08-2026.» or, once that line has been saved again, «Línea fijada junto con el límite
  el 05-08-2026 y guardada de nuevo el 12-08-2026.» The per-row «a
  mano» chip was the only signal there was, and it answers a different question — it marks a typed
  stretch *within* a Limit, so a fully hand-drawn Sector whose Limit is all picked streets shows
  none of them.

  **It costs no column, because the revision history already holds it.** Every write records which
  fields it changed, `Feature::comparable()` carries both `geometry` and `limit`, and the client
  already loads the open record's history — so a Sector generated from picked lines wrote both in
  one revision, while a dragged vertex wrote `geometry` alone. A column would also have been no
  better: `faceAround()` runs in the browser, so the server never witnesses "generated" and
  `Action` has no case for it, and a stored flag would be the client's word for exactly what
  history already says.

  It is worded as provenance and never as a defect, which is the constraint the whole thing turns
  on. The Limit records intent and the geometry records the agreed line; they stop matching the
  moment somebody drags a vertex, and that is expected rather than drift to be corrected
  (CONTEXT.md). So there is no warning colour, no icon, and a unit test fails the build on the
  words that would read as one.

  **It names an act only where history establishes one, which is why a later line is «guardada»
  and not «ajustada».** Writing `geometry` alone in a later revision is what a dragged vertex
  does — and equally what «Generar» does on a reopened Sector whose Limit did not change, the flow
  #49 exists to support. So that clause states the order and the date and stops there: an
  earlier wording announced a hand adjustment over polygons the app had itself just generated,
  which of the three shapes is the one somebody is likeliest to hit.

  **Saying nothing about the act is not the same as saying nothing about the origin**, and the
  first wording of this conflated them: the generated-then-dragged Sector — the state a person is
  likeliest to open — read «Línea guardada el 12-08-2026, después del límite.», which says when the
  line was last written and never that it came from the Limit at all. The origin is in the history
  and does not expire when the line is saved again: the revision that created the Sector wrote
  `geometry` and `limit` together, which is the same fact «fijada junto con el límite» reports.
  Reading only the *newest* write of each field threw it away. Where a revision at or before the
  line's own write recorded both, the sentence now names it before the date; where none ever did —
  a hand-drawn Sector whose Limit was typed later and then regenerated (#49) — it still reads
  «Línea guardada el 12-08-2026, después del límite.», because there is no origin to report.

  The two acts it *can* establish it
  still says outright — generating needs picked lines, so a Limit, and saves the two together, so
  a line last written while no Limit had ever been recorded was not generated, however late the
  Limit was typed. Without that last reading a hand-drawn Sector stopped saying so the moment
  somebody wrote its limit down, and read exactly like a generated one that was later moved.

  **A Sector arrives a third way the ticket did not name: imported.** `ImportService` files a row
  under any Subcategory, Sector included — the one CHANGELOG 0.33.0 is about undoing — and an
  import writes no Limit, so a boundary the municipality published has the same Created entry as a
  hand-drawn one, `geometry` and nothing else. Read from history alone it was told to the user as
  «Línea dibujada a mano», which is the one confident claim this feature could not support. The
  record answers what history cannot: `datasetId` is set on import and nowhere else, the client
  already receives it, and a Sector that has one now reads «Línea importada del catastro.» Only the
  import's own creation is credited — a later shape is a re-import or a dragged vertex,
  indistinguishable, so it falls back to the dateline that names no act. No column, no endpoint:
  one field carried onto the draft.

  Two more things are worth knowing before trusting a sentence. A revision is one save, so a
  Sector generated and then dragged **before** its first save, and one drawn by hand with streets
  picked in the same save, both read as «junto con el límite» — telling those apart needs the app
  to record the act rather than the fields it changed, which is a product question and not a
  column. And a Limit *removed* is not a Limit recorded: the service stores an emptied Limit as
  null, so the revision that cleared it is skipped rather than read as one the line was fixed
  against.

### Fixed

- **A record's history is reloaded after a save** (#50). The sidebar kept the revisions it fetched
  when the record was opened, so a save left it one behind. Merely incomplete while history was a
  list nobody reads twice; wrong once a Sector states how its line came to be from those same
  entries, because a polygon generated a second ago went on calling itself hand-drawn until
  somebody closed the record and opened it again.

## [0.46.0] — unreleased

A Location quality can be corrected on its own.

### Fixed

- **Location quality can now be corrected without re-sending the geometry** (#83). It was read in
  exactly one place — `FeatureService::applyGeometry()`, inside the branch that runs when a geometry
  *is* submitted — so `PUT /features/3` with `{"name": "X", "subcategoryId": 7, "locationQuality":
  "aproximada"}` returned 200 and stored nothing. The field has never been settable on its own:
  before #63 the same request wiped the quality to `sin_coordenada` along with the shape, so #63
  changed only *how* it was ignored, from destructively to quietly.

  The issue asked for a decision, not a line, and this takes its option **(a)**: `apply()` reads
  `locationQuality` like every other optional field, and `applyGeometry` keeps owning the
  with-geometry case. Location quality is a judgement about **how well a record's coordinate is
  known** — about a shape that is already stored — so making the judgement re-state the shape it
  describes is backwards, and option (b) would have written that into the API as a rule. What (a)
  costs is the guard `applyGeometry` used to enforce by construction, so the new door carries it:
  a quality for a record whose stored geometry is null is refused with a message naming «Exactitud»,
  because `LocationQuality::Absent` is derived from having no coordinate and is never chosen.

  Two conditions, each for a reason. The door only opens when `geometry` was **not** submitted, so
  the with-geometry case stays in one place rather than two that can disagree. And it reads the key
  with `array_key_exists` rather than applying it unconditionally, which matters more than it looks:
  `locationQuality()` reads an absent value as `exacta`, so an unconditional write would reset every
  stored `aproximada` on any update that never mentioned the field — #63 again under another name.
  A test holds that line: reintroducing the unconditional write makes it fail.

  Nothing in the browser changes. `App.vue`'s `payload()` always sends `geometry`, as a shape or as
  `null`, so the client never takes the new door; it exists for a caller that sends only what
  changed, and for #23, which has to decide what the client may send for this same field.

  One behaviour changed on the create path, deliberately, because `apply()` is shared: a create that
  omits the `geometry` key **entirely** while naming a quality is now refused instead of storing
  `sin_coordenada` in silence. A create states the whole record, so "there is no shape" and "the
  shape is approximate" contradict each other and refusing is the honest answer. Creates that send
  `geometry` explicitly as `null` or `[]` are unchanged, and the browser always sends the key.

  Submitted empty (`""`) through the new door means `exacta`, not cleared — unlike the optional text
  fields, which clear when sent empty. A record that has a coordinate has no null state for how well
  that coordinate is known, and the default belongs to the helper `applyGeometry` shares.

## [0.45.0] — unreleased

Every package that ships in the bundle is named, and a command says so.

### Documentation

- **`THIRD-PARTY-NOTICES.md` now names every package that ships, and a command says so** (#87).
  Measured 2026-09-15: **67 packages are compiled into `js/` and 67 are named**. The previous count of
  51 was short by sixteen for a reason worth writing down — `@geoman-io/leaflet-geoman-free`
  publishes an esbuild bundle with **23** of its dependencies concatenated in, not the three
  (`splaytree-ts`, `polyclip-ts`, `bignumber.js`) that #86 found. `lodash`, `rbush`,
  `sweepline-intersections` and ten more `@turf/*` packages were in every page load, attributed
  nowhere, resolved by nothing we install.

  The method is the fix. `make notices` builds, then reads three things, because each is blind
  where the next can see: the source maps' `.sources[]` (50 packages), the esbuild `// node_modules/
  .pnpm/<pkg>@<version>` breadcrumbs left inside a published `dist`'s own source text (16 more),
  and a literal-byte probe for a package that ships as bytes rather than as a module —
  `@mapbox/maki`'s icons are inlined as assets, and an asset carries no source-map entry. It
  exits non-zero listing every shipped package with no row in the file, and CI now runs it: the
  way this file went stale was that keeping it current was a procedure nobody was made to run.

  All three sources have a floor, because the failure worth fearing here is a census that goes
  blind quietly rather than one that goes wrong loudly. Too few packages in the maps is not a
  bundle that shrank; a probe whose bytes are absent is not a pass; and geoman in the bundle with
  no breadcrumbs found inside it is the scan having lost its footing, not a `dist` that stopped
  inlining — without that last one a minified republish upstream would drop the count back to 51
  and print, confidently and wrongly, that the file named all of them.

  And "no row in the file" is now what the reconciliation actually reads. It used to grep the
  whole file for the backticked name, which prose satisfies: deleting the seven table rows for
  the packages that reach the bundle only through the inlined path left the sentence that names
  them, and `make notices` still reported 67 of 67 and exited 0 — this file's own failure mode,
  a census going blind quietly, reproduced inside the check written to prevent it. It now parses
  the Component column of the tables and compares sets, so an attribution has to be a row.

  Two things the file used to say were wrong, and both were wrong in the same direction — a real
  string mistaken for a path anyone could look up. The `node_modules/.pnpm/splaytree-ts@1.0.2/…`
  path it cited is not a path in this repository (we install with npm; there is no
  `node_modules/.pnpm`), and `jq -r '.sources[]'` does not name it: it is a string inside geoman's
  published `dist`, so it lives in `.sourcesContent[]`. Chasing that is what turned three inlined
  packages into sixteen. And deriving a package name from a source path needs the **last**
  `/node_modules/`, not any — a checkout whose `node_modules` is a symlink out of the tree yields
  paths with the repo prefix repeated inside them, and a plain `grep -o` over those invents a
  package called `opt`.

  Licences are read from each package's licence file, never from the `license` field: two
  packages (`@nextcloud/vue`, `@nextcloud/event-bus`) publish no licence file at all and are
  marked as the exception rather than blending into the table, and `sweepline-intersections` has
  no copy in a development checkout at all — its MIT text was read from the published tarball.
  The full texts of MIT, ISC and Apache-2.0 (`dompurify`'s election) are now in the file beside
  Leaflet's BSD-2 and `splaytree-ts`'s BSD-3, because those licences require the text to travel
  with a binary redistribution and `js/` is one. GPL-3.0's text ships as
  `LICENSES/GPL-3.0-or-later.txt` instead: the docs gate reads every fenced block in a governed
  `.md` as commands, and the GPL says "make sure" eight times over.

## [0.44.0] — unreleased

Exports say whose data they carry.

### Added

- **Exports of OpenStreetMap-derived records name the ODbL licence** (#28). `GeoJsonWriter`,
  `CsvWriter` and `KmlWriter` carried no licence statement of any kind. Nothing was wrong while
  the files stayed inside a clinic — ODbL's share-alike triggers on public use — but handing a
  Sector file to the municipality is a normal thing for a CESFAM to do, and the app offers three
  export formats to make it easy. Attribution has to name the licence, not only the source, so the
  line is `© OpenStreetMap contributors, licencia ODbL` with the copyright URL.

  An OSM-derived Dataset arrives stamped `source_es: OpenStreetMap` with a `source_url` (ADR-0004),
  and `GeoJsonReader` joins those into `sourceRef`. Read from there **and** from `official`, the
  row as the authority published it. `sourceRef` alone would not have held: it is the Fuente box in
  the form, so clearing or rewriting it on an imported street would drop the licence off every
  export of exactly the record the notice is about, while the geometry in the file is still
  OpenStreetMap's — and it would drop off silently. `official` is written only by an import
  (`FeatureService::applyProvenance()` runs for a Provenance; a person's edit passes none,
  ADR-0003), so no edit can reach it. It rides in the export row and is written to no file: it is
  the whole published record, contact fields included, and each writer takes only the keys it maps
  — held to that by a test per writer. Found by review; the first version of this slice keyed on
  `sourceRef` alone.

  Matched loosely — a source somebody typed that merely mentions OSM makes a file carry a line it
  did not strictly owe, while a stricter match that misses one makes the file a licence breach, and
  those two costs are not comparable.

  **Where it goes is decided by the fourth criterion, that it must not break whoever opens the
  file.** GeoJSON gets a top-level `license` foreign member, which RFC 7946 §6.1 allows and
  `GeoJsonReader` ignores, so the export that goes back in still goes back in — pinned by the
  round trip in `ExportTest`, now run on an OSM-stamped fixture. KML appends it to the
  `<Document><description>` that already says what the format cannot carry; an `<atom:author>`
  would have cost a namespace declaration for a line shown in the same panel. CSV gets **a
  column**, because RFC 4180 has no comment syntax: a line above the header breaks header
  detection and a trailing line of a different width breaks a strict parser, so a cell is the only
  place a spreadsheet can hold it. The column appears only when the set needs it, and is filled
  only on the rows it is about — a notice repeated over a mixed set would say the municipality's
  own catastro belongs to OpenStreetMap.

  An export holding none of OSM's data carries nothing, in all three formats. That half is not
  tidiness: an attribution printed on every file is read as boilerplate, which is how the real one
  stops being seen.

## [0.43.0] — unreleased

The app icon, white where the surface behind it is coloured.

### Fixed

- **The app icon was the black one on every surface that draws it on a dark background** (#26).
  `img/app.svg` and `img/app-dark.svg` were the same 311 bytes, neither carrying a `fill`, so both
  drew black — and the light variant is the one nearly everything asks for. The plan had the pair
  backwards, and the server settles it: `AppManager::getAppIcon()` takes `app.svg` **without**
  `$dark` (`lib/private/App/AppManager.php:118`), which is what the header's current-app button and
  the app menu get (`lib/private/NavigationManager.php:353`) and what the favicon is rasterised from
  (`Theming\Util::getAppIcon()`). All three paint it on the primary colour. The only caller of
  `getAppIcon()` asking with `dark: true` is the apps list in Settings
  (`lib/private/legacy/OC_App.php:440`), a white page — and this app asks for `app-dark.svg` by name
  itself, in `AdminSection::getIcon()` (`lib/Settings/AdminSection.php:32`), for its own Settings
  section, which is white too. So `app.svg` gained `fill="#fff"` and `app-dark.svg` keeps none, and
  each file now carries a comment saying which surface it serves and, for the black one, which two
  consumers break if it is deleted.

  The convention is cited from the server's source rather than from the manual because the manual
  does not contain it: the `info.xml` page says the navigation icon "defaults to app.svg" and the
  Icons page says nothing about `app-dark.svg` or about colour at all (both checked against the 34
  manual on 2026-09-15). Core states it in a CSS comment instead — "App icons are bright by default"
  in `core/src/components/AppItem.vue`, which flips them with `primary-invert-if-bright` when the
  circle behind them is bright.

  Two things about the comments themselves are load-bearing and are now guarded by tests, because
  both fail silently. They sit **inside** the root element: `IconBuilder` decides a file is an SVG
  by looking for a leading `<svg` or `<?xml`, so a comment above the root costs the app its favicon.
  And they cannot spell a CSS custom property out in full — a double hyphen inside an XML comment is
  illegal, librsvg refuses the whole file, and the first draft of this fix did exactly that and was
  caught by rasterising it through Imagick rather than by reading it.

## [0.42.0] — unreleased

Four copies of one fixture, now one.

### Changed

- **One `sector()` fixture for the whole integration suite** (#60). Four tests carried the same
  twenty lines apiece — `SectorTest`, `AssignmentTest`, `LimitTest` and `BoundaryDatasetTest` —
  each hard-coding the fields it did not care about and taking as an argument the one it did: a
  colour, a set of rings, a Limit, nothing at all. `IntegrationTestCase` already owned `place()`
  for exactly this job, so `sector()` now sits beside it and opens the same `$extra` door: a test
  passes only the field it is about, and the fixture still goes through `FeatureService`, which is
  what makes the Places inside the new boundary get assigned as they would be in the app
  (ADR-0008).

  The issue was opened for a leak — Sectores surviving a run and claiming Places in the next one —
  and was re-titled on 2026-09-14 after the owner measured that all four copies did register their
  fixture for cleanup. What was left was the duplication, which is why this is a `Changed` and not
  a `Fixed`.

  Two fixtures changed shape in passing, neither asserted on: the Sectores in `LimitTest` now sit
  on the shared default boundary instead of a square of their own, and `BoundaryDatasetTest`'s
  hand-drawn Sector is that square rather than a triangle. And the copy cannot quietly come back as
  it was: the shared helper is `protected`, so a `private sector()` in a subclass is now a fatal
  error rather than a shadow.

## [0.41.0] — unreleased

An update that omitted the geometry destroyed it.

### Documentation

- **`splaytree-ts` declares a licence that does not exist, and ships** (#27). Its `package.json`
  says `"BDS-3-Clause"`; its `LICENSE` file is a correct BSD 3-Clause. Recorded in
  `THIRD-PARTY-NOTICES.md` — as a paragraph a person reads, not an allowlist a scanner reads;
  nothing machine-readable exists to wire one to yet. The issue named `@turf/polygonize` as the
  parent; measured, it arrives inlined inside `@geoman-io/leaflet-geoman-free`'s published `dist`,
  together with `polyclip-ts` and `bignumber.js`.

  **Corrected within the day.** The first version of that note said whether the code reached `js/`
  was *"not established"*, because the `SplayTree*` symbols appear in neither bundle. Minification
  erases symbol names, so that evidence could not have shown presence even if present — the same
  mistake as grepping a file for a value that lives in a process. String literals survive it:
  `Concurrent modification during iteration` appears once in `js/territorio-main.js`, and the
  source map names the package outright. It ships, BSD-3-Clause requires its notice be reproduced
  in binary redistribution, and the text is now in the file. Found by `/code-review`.

  Counting the shipped set to check that claim surfaced a larger gap: **51 packages are compiled
  into `js/` and 18 are named**, and the file's documented update method — read the two webpack
  banner files — structurally cannot see the rest. Filed as #87.

### Fixed

- **Reclassifying a record into or out of a boundary Subcategory reassigned nothing** (#84).
  `reassignAround()` was given the geometry the record had before the write, and decided whether to
  sweep by comparing it. A Subcategory change moves no shape, so the comparison said "nothing
  happened" in both directions: a Zone promoted to `sector` claimed nobody, and a Sector demoted out
  of one kept every Place pointing at an id no read would resolve — «sin asignar» in the sidebar for
  records that were, in the database, assigned.

  It is ADR-0008's invariant — what falls inside a boundary is derived at the one door every write
  comes through — broken by an ordinary edit that never touches a shape, and reachable from the UI
  since long before #63; that change only added a second route to it.

  The seam now also receives what the record **was**: `$wasDivision`, read at the same moment and
  for the same reason as the old geometry, because after the write there is no way to ask. Becoming
  a boundary or ceasing to be one sweeps; everything else behaves exactly as before, and a rename
  still reassigns nobody.

  Also the third direction: Sector to Unidad Vecinal with the shape unchanged — both are boundaries,
  so a bool could not see it; the sweep now compares the division slug and runs from `write()`, the
  one door.

- **An update that omitted the geometry destroyed it** (#63). `applyGeometry` read the key with
  `?? null`, so an **absent** geometry and an explicitly **empty** one were the same thing — and
  both wiped four columns at once: the shape, the bounding box, the Kind and the Location quality.
  Every other optional field already drew that distinction with `array_key_exists`, and the comment
  three lines above states the rule plainly. Geometry was the field it was not true of.

  Found on 2026-08-12 by doing it: a Unidad Vecinal updated with only a name and a subcategory lost
  its polygon, its bounding box and its Kind. **The save reported success**, the record simply
  vanished from the map, and only the revision history made it recoverable. A Unidad Vecinal's
  boundary comes from a Cadastral Dataset — the clinic reads it and never draws it — so there is no
  redrawing it by hand.

  Absent now returns early and changes nothing. Sending `geometry` explicitly empty still clears all
  four, so clearing stays possible; it just has to be asked for.

  **The distinction is a parameter, not a caller's habit.** `apply()` serves both doors, and a new
  Feature carries `kind = ''` — which is not a Kind — so a create with no geometry key at all must
  still take the empty branch to get its Place and its Absent location quality. `apply()` and
  `applyGeometry()` now take `$whole`, so the rule lives in the function that applies it rather
  than in a line the next caller has to remember; the class docblock plans a second door, and that
  door would have inserted records with no Kind.

- **`tools/browser-check.py` could not pass on a fresh instance** — unrelated to the above, found
  while running the gate for it. Nextcloud's first-run wizard opens a modal whose overlay
  intercepts every click, so Playwright retried the first one 55 times and the run died in a wall
  of pointer-event noise naming the wizard rather than the app. The gate had only ever been green
  on an instance somebody had already clicked through by hand. It now clears the wizard itself,
  beside `log_in` — in the gate rather than in `make instance-up`, because `test-browser` has no
  prerequisite and is the one command run against an instance that is already up.

### Changed

- **`limit`, `picked` and `pickedGeometry` are built by one function.** Two places made a Sector
  draft — starting a new one and reopening a saved one — and each held its own list of the three
  fields only a Sector carries. They have drifted apart twice: `limit` absent meant `addLimitNote`
  had nothing to key on, so a tramo typed before the first save was dropped (#48); `pickedGeometry`
  absent meant `save()` carried undefined over a fresh object (#49). Both were one caller holding a
  field the other did not, and the next field would have been the third.

  `sectorFields()` lives beside the rest of the Limit shaping, so it is a thing that can be tested
  rather than a shape that has to be remembered twice.

## [0.40.0] — unreleased

A scan that does not ask twice.

### Changed

- **The duplicate scan stopped proposing pairs the table already holds.** `scan()` offered every
  candidate to the database on every run and let the unique index refuse the ones already recorded.
  The outcome was right — that is what the index is for, and ADR-0011 leans on it — but a refused
  insert in PostgreSQL still writes a row version for autovacuum to collect, and still burns a
  sequence value. `oc_territorio_duplicate` on the dev instance has taken **1,778,856 inserts**
  against **8,852 live rows** — a total that counts the successes and every other writer too, but
  the gap is the shape of it — and a single re-scan asked for **704** the table already held.

  The known pairs are read once per scan and skipped. The index remains the authority: this only
  filters what was already there when the scan began, so two scans at once still cannot both record
  the same pair.

### Notes

Pinned by a test that could not have been written against the outcome, because the outcome was
always correct — `testScanningTwiceDoesNotOfferTheSamePairTwice` has passed all along. `nextval` is
not transactional in PostgreSQL, so a refused insert moves the sequence exactly as a successful one
does, which makes the sequence the one thing that can tell "proposed nothing" from "proposed
everything and was refused". It failed at 704 before the change.

## [0.37.0] — unreleased

Asking the taxonomy once.

### Changed

- **Assignment no longer re-reads the Category tree.** Two paths asked it, and both asked for the
  whole thing — two full table scans for an answer that cannot change while the service lives.
  `isBoundary()` asks on every create, update, retire and restore; `boundaries()` asks again each
  time a boundary write empties its cache, so a request that moved *N* boundaries read the taxonomy
  *N+1* times.

  Measured on the dev instance: **55,576 sequential scans of `oc_territorio_category` against 431
  writes of it**. One run of the integration suite added 543 of them for 933 record writes; the same
  run adds **193** now.

  One map, `subcategory id => division slug`, answers both: `boundaries()` needs to know *which*
  division a Subcategory is, `isBoundary()` only that it is one — and an `isset` answers the second
  without walking the first. The loop that built it existed twice.

### Notes

**Memoising `TaxonomyService::tree()` itself was tried first, and is wrong.** It is the obvious place
and it broke `RampRepaint`: the colour repair writes a Category and reads the tree back in the same
request, so the memo served it the colours from before its own write. Two of that suite's assertions
failed on the clinic's own recoloured Category — not a hypothetical about #36 making the tree
editable, but a job that does it today.

What is cached instead is narrower: which Subcategories are divisions. #40 specifies that neither
those nor their Category can be removed and that a rename never changes a slug — but that is
specified, not shipped, and what guarantees this today is simpler: nothing in the app writes the
taxonomy outside the install-time repair.
## [0.33.0] — unreleased

Generar says which failure it is.

### Fixed

- **«Generar» on a reopened Sector failed, and blamed the streets.** `faceAround()` polygonizes the
  cached *geometries* of the picked lines, not their ids. `draftFrom()` rebuilds `picked` from the
  stored Limit — which is exactly what #15 put the names there for — and has nothing to fill
  `pickedGeometry` with, so a reopened Sector reached `faceAround` with an empty list, got null back,
  and was told «Las calles elegidas no cierran alrededor de ese punto». About streets that do close,
  so the person went and re-picked streets that were already right.

  The shapes come back on their own now. Every picked line that comes into view refills its own, so
  reopening a Sector and panning over its límite makes «Generar» work again **without re-picking
  anything**. That is safe only because the guard demands *all* of the picked lines: a partial set
  still closes, into a larger face than the one meant, with nothing on screen to say so — which is
  the silent failure `keepPickedGeometry` was written against. A half-filled cache reads as not
  ready, never as a smaller Sector.

  Until it is full the button is greyed out with the reason beside it, rather than offered and
  failing. ADR-0007 keeps hand-drawing first-class throughout, and the hint says so.
- **A save dropped the shapes of the lines just picked.** `save()` carried `picked` across the
  rebuild and not `pickedGeometry`, so pressing Guardar and then «Generar» failed the same way, on
  lines chosen a moment earlier. Both halves now cross the save.
- **A geometry that is not line work counted as a shape.** A Boundary row may carry one — an empty or corrupt
  geometry decodes to null — and `keepPickedGeometry` stored what it found, so a Limit whose lines
  were all like that passed a check for `undefined` and then produced no face: the same message,
  reached by another road. Worse once the cache refills on every map settle, where a null would have
  *overwritten* a shape already held. And nullness was only half of it: a boundary Dataset is
  filtered by Dataset and not by geometry, so a Point, a Polygon, or the one-repeated-coordinate
  LineString a KML import produces is pickable too, and each counted as ready. The guard asks
  `stretchesOf` now — held and usable are different questions, and only the second one matters.
- **Unpicking a street from the Limit left its shape in the cache.** `removeLimitPart` dropped the
  ids without going through `keepPickedGeometry`, so a shape outlived the pick it belonged to.
  Clicking the line on the map was never affected — that path always went through it.

### Notes

The first version of this refused outright, on the reasoning that the shapes could not be recovered.
An audit showed that was wrong: `loadBoundaries()` already receives the shape of every picked line
that comes into view, so one line rehydrates the cache as the person pans. The refusal became the
fallback rather than the answer.

The browser gate covers both halves — the button must survive a save, and a reopened Sector must
generate again with no re-picking and never say «no cierran» — and both were proven by reintroducing
each bug and watching the matching check fail.

## [0.32.0] — unreleased

One list of the streets a Limit runs along, not two.

### Changed

- **The Limit picker showed the same streets twice.** «Tramos del límite» is built from the Limit;
  «Elegido», thirty lines below it, was built from the picks. They always held the same streets —
  `recordLimit()` runs on every pick, so a picked id is already a Limit row by the time either list
  draws.

  «Elegido» was the strictly worse copy. It resolved names from the boundary rows *currently on
  screen*, and those are replaced wholesale every time the map settles and capped at three thousand
  against a comuna's eleven thousand — so a street a person had chosen degraded to «Fuera de la
  vista» as soon as they panned. The Limit row keeps the name permanently, which is exactly why #15
  put it there. Its «Quitar» was a second path to one outcome, and `removeLimitPart` unpicks the ids
  as well, so the remaining one loses nothing.

  Gone with it: `pickedGroups()`, its test block, and the `picked` prop — about sixty lines for no
  information a person could have read.

## [0.31.0] — unreleased

A pin you can click while looking at what was retired.

### Fixed

- **Clicking the map did nothing while «Ver retirados» was on.** The map draws the live Registry
  whatever that toggle says, and `listFor()` answers with the retired list *alone* once it is on —
  deliberately, so a search still reaches a record somebody is trying to restore. With the toggle on
  the two sets are disjoint, and `select()` was resolving the clicked id against the list's answer.
  It found nothing: the shape took the selected styling, the sidebar never opened, no history was
  fetched. The click looked accepted and was dead.

  Selecting is not a list operation. It resolves against the two sets the app holds, not against one
  view of them — the Capas filter narrows what is *listed*, never what exists.
- **«Abrir ficha» in the duplicate queue was dead the same way**, and more often: the queue is
  unfiltered on purpose, so its ids came from the whole live Registry while `select()` answered from
  the filtered list. Any Capas toggle or any search was enough. The same fix covers it.
- **Saving a record opened that way reported a failure over a save that had worked.** `applyIncoming`
  closed the draft whenever a record's retired flag differed from the toggle — which is every live
  record once one is reachable with «Ver retirados» on — and `save()` then read `picked` off the null
  that left behind. The user saw «No se pudo guardar. Revise la conexión» and an empty sidebar for a
  record the server had already accepted. The guard now closes the draft only when the record has
  actually left the screen, and `save()` reads what it needs before the merge rather than after.
- **A record retired by somebody else showed no restore banner.** `selectedIsRetired` asked whether
  the retired list was on show as well as whether the record was in it. The list is never cleared
  once loaded, so a record a colleague retired could be opened from the duplicate queue with the
  toggle off, and it came up with no banner and a live «Guardar». Membership is the whole question.

### Notes

Only a browser could see it. Both sets are correct on their own and neither suite pairs them, which
is the same shape as `Guardar` doing nothing for eight slices. The browser gate now clicks the
Sector it drew itself with the toggle on and requires a sidebar; reintroducing the old lookup and
rebuilding reddens it.

## [0.30.0] — unreleased

Saying who changed the límite.

### Fixed

- **A Sector's Limit never reached its history.** `comparable()` is keyed as the client knows a
  Feature and emits `limit`; `getUpdatedFields()` is keyed by property, and the property is
  `boundaryLimit` — the column is `boundary_limit` because `limit` is reserved. Of sixteen keys it is
  the only one that diverges, and `diff()` gated one on the other, so `isset($writing['limit'])`
  could never be true. Every Limit edit recorded «Editado» with nothing under it, against a
  promise written into `Feature.php` itself: *"quién cambió el límite is exactly the kind of question
  history exists to answer"*.

  An alias on the entity now carries it — not a rename, because `RevertService` and `ImportService`
  both key off the client names. A reflection test asserts every `comparable()` key resolves to a
  declared property through that alias, so the next reserved word cannot open the same hole quietly.

### Notes

**The test was the worse half.** `testChangingTheLimitIsInHistory` flattened `array_keys` across
every history entry, and `insert()` builds the Created entry from `comparable()` directly, bypassing
the updated-fields gate — so it always carried `limit` and the assertion passed on creation while
every update dropped it. It now asserts on the update entry alone, and fails when the alias is
removed. A test that cannot fail is worse than the bug it was meant to hold.

Confirmed in the running app: after changing a Limit the sidebar now reads
`Límite: (vacío) → prueba de historial`.

## [0.29.0] — unreleased

Names are text, not markup.

### Fixed

- **A stored name could redirect everyone who opened the map.** Leaflet assigns string tooltip
  content with `innerHTML`, so `bindTooltip(feature.name)` parsed a name as markup. Nextcloud's CSP
  stops script and every exfiltration channel — `script-src` is nonce-based, `connect-src`, `img-src`
  and `font-src` are all `'self'` — but **nothing covers navigation**, and an injected
  `<meta http-equiv="refresh">` was confirmed navigating a real browser. That made it a stored
  redirect to a page of the attacker's choosing, arriving from a trusted internal app.

  The realistic source is not a colleague — ADR-0005 already lets any user edit any field, so they
  gain nothing — but **an imported cadastre**: the second call site renders names straight out of a
  file the clinic did not write. Both now pass a text node, which cannot be parsed at all, rather
  than an escape, which is a thing you can get subtly wrong.

### Notes

Guarded in `tests/js/imports.test.js`, beside the two other mistakes that compiled and shipped. The
browser gate was tried first and abandoned after five runs: a tooltip only exists while Leaflet is
hovering a shape it has chosen to render, and it opens them on a timer, so the runs were measuring
the render lifecycle rather than the bug.

Proven both ways against the running instance, with a Unidad Vecinal renamed to carry the payload:
fixed, the tooltip holds the literal string with zero child elements and the page does not move;
reverted and rebuilt, the probe dies with *"Execution context was destroyed, most likely because of
a navigation"*.

## [0.28.0] — unreleased

Keeping what somebody typed before they saved.

### Changed

- **A re-import no longer keeps two hand-maintained lists of the same twelve fields.** `differs()`
  listed them beside `input()`, which lists them already. The two had to agree by hand, and where
  they disagree the loss is silent — not on an ordinary re-import, where the authority's own blob
  answers first and it is the whole row, but on the one path that reaches the fields at all: a
  record a **revert** put back, which writes old values without marking an override (ADR-0009), so
  the record disagrees with an official blob that is still current. `differs()` now derives from
  `input()`, with the two fields normalised that are stored in a shape they do not arrive in.
- **Both taxonomy joins come from `TaxonomyService::tree()`.** `ImportService` and `ExportService`
  each rebuilt the Category↔Subcategory join from the two mappers; four constructor dependencies and
  four imports went with them.
- **`DatasetMapper::findOrCreate` no longer takes an `Origin`.** Both callers passed
  `Origin::Cadastral` and the column is written once and never read. The enum stays — CONTEXT.md
  needs the word — and the parameter comes back when a Curated Dataset can be created.
- **`ContactFields::KEYS` is gone.** It was read by no production code: a canonical third copy of a
  list the two writers already hold, which is worse than two, because it looked like the authority
  while the writers were the authority. They must keep their own maps — GeoJSON says
  `contact_person`, a CSV column says `Persona de contacto` — so what has to hold is that the two
  agree, and a test now makes that true rather than claimed.

### Fixed

- **The gate left a live Sector behind on every run.** The check added for #48 draws and saves a
  hand-drawn Sector and never retired it, so five had accumulated, one per run. A Place left behind
  is harmless; a **boundary** is not — every Place inside it is reassigned to it (ADR-0008), so
  `ExportTest` began failing on a `sectorId` that had drifted to a Sector a gate run had left on the
  map. `retire_the_runs_record` already existed for exactly this, and its docstring already said it
  had broken two integration tests that way once before.
- **The browser gate drew into a full map, and mostly missed.** It hid the cadastre once at
  start-up — but a View is per-session and nothing persists it, so every one of the file's ten
  reloads brought the Unidades Vecinales straight back. Only the first draw was ever protected.
  Measured: three misses in five runs, and then every run once the map had filled up, at which point
  no branch could get a green gate at all. The boundaries now come off the map for the duration of
  each draw and go back afterwards, through the app's own Capas toggles — ten draws in ten, each
  after a reload. `draw_a_sector`'s `offset` went with it: it worked around a second draw landing
  inside the first one's polygon, which cannot happen once that polygon is hidden.
- **A tramo typed on a Sector that had never been saved was discarded**, with nothing said. ADR-0007
  keeps hand-drawing first-class and a hand-drawn Sector's whole Limit may be typed stretches — but
  `startSector` built the draft with no `limit` key at all, `addLimitNote` keys on it being defined,
  and the picker cleared its input either way. So the text vanished. It worked after a save and
  reopen, because that path sets an empty Limit, which is why the browser gate never saw it: every
  check there opens an already-saved Sector.

### Notes

The gate now reaches a Sector **before** its first save, which it never did before. Two smaller
things had to be true first, both of the same shape as issue #53 — a check that operates on
whatever the sidebar happens to hold. `draw_a_sector` now returns whether it actually drew, so a
caller cannot carry on regardless; and it takes an offset, because its position is fixed within a
run and a second draw landed inside the first one's polygon and selected it. NUDGE existed for
exactly that hazard, one scope too wide.

## [0.27.0] — unreleased

A pin that says what kind of place it is.

### Added

- **Every Category is drawn with its own pictogram**, from Maki (CC0-1.0): a cross for Salud, a
  school, a pitch, a police badge, a recycling mark, a community block, a town hall, a bus, a place
  of worship, a shop, and an open ring for "Sin clasificar". They appear in the map pin, the list
  swatch and the Capas legend, all from one function — so the legend cannot disagree with the map.
- `place-of-worship` rather than `religious-christian`, because the app must not assume a
  denomination. `circle-stroked` for "Sin clasificar", because that is a real seeded Category
  meaning *nobody has decided yet* and must not read as an error.
- A Category a clinic adds later, and a Feature whose Subcategory no longer exists, both fall back
  to the neutral glyph rather than rendering a blank dot — a blank reads as a rendering failure.

### Changed

- **Pins are 22px, not 18px; list swatches 18px; Capas swatches 16px.** Maki draws on a 15-unit grid
  and needs roughly 12px of actual mark before its detail survives. At 11px inside an 18px dot,
  `hospital` and `bus` still read but `school`, `police` and `place-of-worship` turned to mush —
  checked by rendering all eleven at four sizes and looking. The pin is the only place a glyph has
  no label beside it, so it is the one sized for legibility on its own.
- `webpack.config.js` loads `@mapbox/maki` as `asset/source`. The Nextcloud default for SVG is
  `asset/inline`, a data URI, which cannot be recoloured and cannot be inlined into the Leaflet
  `divIcon` — and both are needed.

### Fixed

- **The browser gate no longer edits the cadastre when a draw misses.** `draw_a_sector` waited for a
  colour input to confirm it had a Sector draft — but a Unidad Vecinal is a territorial division and
  carries one too, so a canvas click that selected a boundary instead satisfied the guard, and the
  gate typed a name and a colour into the cadastre and saved. Three Unidades Vecinales were renamed
  that way. A history section is the tell: it renders only for a record that already exists, and a
  Sector drawn a moment ago has none (issue #53).

### Notes

This is the half of ADR-0013 that does the identifying. Hue only groups the eleven Categories into
six families, because `2·C·sin(Δh/2)` says eleven separated hues need a chroma louder than the
palette that was replaced. **A dichromacy touches the hue and never the shape**, which is why the
glyph carries identity and the ramp is allowed to stay quiet.

The browser gate creates a Salud record, asserts its pin carries the `hospital` glyph filled with
the seed's own `salud` colour, and retires it again. Removing the glyph makes it report `None`;
mapping Salud to the wrong icon makes it report `'shop'`.

### Documentation — 2026-08-08

The repository's first documentation audit. No behaviour changed; several documents that described
behaviour were wrong.

- **A real clinic was named in code.** `src/filters.js`, three test fixtures and their assertions
  carried the establishment this app was first built for. Which clinic an install serves is instance
  configuration, so the fixtures now use a synthetic name and a `cesfam_de_prueba` source slug.
  ADR-0004 keeps the slug it recorded, with a dated correction saying why.
- **Every ADR has a `Status:` field**, dated from the commit that introduced it. ADR-0001 justified
  the app partly on "a REST surface other apps read" while `tests/unit/RoutesTest.php` fails if one
  is added; the new **ADR-0014** records that the surface is `RegistryService`, consumed by
  injection, and ADR-0001's Status links forward to it.
- **THIRD-PARTY-NOTICES.md was derived from the manifests only**, so DOMPurify (dual-licensed
  MPL-2.0 or Apache-2.0), `focus-trap`, `tabbable` and `escape-html` shipped in every page load
  unattributed. It is now reconciled against `js/*.LICENSE.txt`, which is what the bundle says about
  itself, and it names both sources.
- **`package.json` no longer declares a version.** It said `0.1.0` while `appinfo/info.xml` said
  `0.27.0`; info.xml is the one Nextcloud reads and the one every slice bumps, so the second
  declaration is gone rather than duplicated.
- **Nothing blocks a merge here**, and `CLAUDE.md` and the `slice` skill said otherwise. A private
  repo on this plan cannot have a protected branch and nobody can approve their own pull request, so
  the browser gate is a step you do not skip and the approval is a self-review checkpoint. The skill
  also documented `git push origin main`; it now lands through a pull request.
- `.github/` and `.githooks/` did not exist: CODEOWNERS, the issue forms, the PR template, the
  pre-commit hook and the `docs` workflow are now in place from the org's canon. The vendored
  checker cannot run yet — see `CLAUDE.md`.
- New `docs/index.md` names the owner of each fact.

## [0.25.0] — unreleased

Geometry that draws correctly and means nothing.

### Fixed

Three ways a boundary could look right on the map and be wrong underneath. All silent — the shape
drew, the import succeeded, and nothing anywhere said the geometry was broken.

- **An unclosed ring contained nobody.** GeoJSON requires a ring to repeat its first position last
  (RFC 7946 §3.1.6) and real files do not always do it. `PointInPolygon` needs four positions before
  a ring has an inside, so an unclosed **triangle** assigned every Place inside it to nothing —
  while Leaflet, which closes rings when it draws, showed a perfectly ordinary Sector. An unclosed
  **quad** already has four positions and worked, so closure was silently required at three vertices
  and silently optional at four. Rings are now closed once, on the way in, in `Geometry` — which is
  also what stops the app **re-exporting invalid GeoJSON** to whoever asked for it. A ring that
  cannot bound an area at all is refused with a message naming the ring.
- **KML holes were dropped.** `KmlReader` read `<outerBoundaryIs>` and ignored `<innerBoundaryIs>`,
  so a park drawn around a lagoon claimed every record standing in the lagoon.
- **A `<MultiGeometry>` placemark imported shapeless.** One shape inside is now unwrapped — MyMaps
  writes that often enough that refusing it would throw away ordinary records. Several shapes make a
  Multi\* geometry this app does not store, and it now arrives as a type the import **refuses**, so
  the batch counts and reports it. Arriving with no geometry made it indistinguishable from a record
  somebody never located.
- **One degenerate line poisoned a whole street.** A KML `<LineString>` with a repeated coordinate
  imports as `[[x,y],[x,y]]` — two positions and no length — which passed the picker's length check
  and made `polygonize` refuse the entire collection. The editor reported that as *"las calles
  elegidas no cierran alrededor de ese punto"*, which was untrue; the streets closed perfectly. And
  because picking by name picks every fragment of a street, one bad row broke the whole pick.

### Notes

Each fix fails its own test when reintroduced. The unclosed ring is pinned twice: in `GeometryTest`
on what gets stored, and end to end in `AssignmentTest`, where removing the fix leaves a Place
inside a triangle assigned to nobody.

## [0.24.0] — unreleased

Making undo finish the job, and stopping a Sector handing its shape to the next record.

### Fixed

- **Closing an edited Sector wrote its polygon onto whatever record you opened next.** Drag a vertex,
  click another record in the list, save it — and it saved with the Sector's shape, because a drawn
  shape wins over typed coordinates. Geoman binds `remove` to `disable()`, and `disable()` fires
  `pm:update` when a vertex has been dragged, so tearing down the draft layer ran a handler that was
  still bound and emitted the *old* record's geometry into the *new* record's draft. One line:
  unbind before removing. The browser gate now drags a vertex, switches records, saves, and reads
  the geometry back out of the change feed — removing the fix makes it say
  *"was saved with a Polygon after an edited Sector was closed — it took the Sector's shape"*.

A revert is the only recovery path this app has — ADR-0009 chose that over a backup feature — and
it had three holes. All of them silent, and all of them reported as success.

- **Undoing an import that created a Sector or a Unidad Vecinal left every Place still pointing at
  it.** `RevertService` retired the boundary through the mapper, which writes the column; only
  `FeatureService` also lets go of what the boundary covered (ADR-0008). So the Registry held an
  assignment to a record no read will resolve, and the sidebar said "sin asignar" about a record the
  database said was assigned. Registry-wide, and the revert counted those records as updated.
- **Undoing a redraw put the old shape back and left the assignments where they were.** Same duty,
  the other path: `FeatureService::revertTo()` wrote the geometry without reassigning, so a boundary
  that shrank off a Place left the Place still claiming to be inside it.
- **A batch that touched one record twice was only half undone.** A revert stamps its own Revisions
  as it goes, so after undoing the newer entry the older one saw the revert's own write and decided
  a colleague had been there. The record stayed on the map, counted as **"omitido"** — which reads
  as deliberately protected rather than missed. It happens whenever one file holds a Place and a
  boundary covering it, because importing the boundary reassigns the Place inside the same batch.
- `Action::Merged` had no arm in the revert's `match`, so a merge recorded under an import id would
  have been a 500 mid-revert. Unreachable today; a revert is the wrong moment to fall over.

### Notes

`RevisionLog::forImport()` carried a comment asserting no import touches one record twice. That was
true of duplicate rows, which are collapsed before anything is written, and missed reassignment
entirely. Corrected.

`tests/integration/RevertAssignmentTest.php` crosses revert and assignment, which nothing did
before — each of the three fixes fails exactly one of its tests when reintroduced.

## [0.23.0] — unreleased

Making the map's colours mean one thing each.

### Changed

- The eleven **Category colours** are now a designed low-chroma ramp instead of a Tailwind-600
  grab-bag. Hue carries a *family* of six, lightness distinguishes the two members inside a family,
  and identity will be carried by a pictogram (#20). The reason it has to work that way is
  arithmetic, not taste: `2·C·sin(Δh/2)` says eleven separated hues need a chroma louder than the
  palette being replaced. See [ADR-0013](docs/adr/0013-hue-groups-categories-the-glyph-identifies-them.md).
- The ramp is **quiet on purpose**, so that a Sector's boundary and its name can be the loudest
  thing on the map rather than competing with eleven category dots.

### Fixed

- A Sector nobody recoloured was drawn in **exactly the colour of every school** —
  `DEFAULT_SECTOR_COLOUR` and Educación were both `#2563eb`, byte for byte.
- `comercio_economia` sat at **2.94:1** against a white page, and `seguridad` at **2.27:1** against
  the dark theme. Both were below the 3:1 that non-text UI needs and nothing had ever measured them.
- `medioambiente` and `movilidad_transporte` were ΔE 0.049 apart — one colour at 18px. Under
  tritanopia **five** pairs fell below that threshold and **three** of them were literally the same
  colour (ΔE 0.006, 0.009 and 0.015).
- `UNKNOWN_COLOUR`, which marks a Feature whose Subcategory no longer exists, was byte-identical to
  `sin_clasificar`. The list already refuses to *label* those two alike; the colour said they were
  the same thing anyway.

### Added

- `EnsureSeedData::ensureRamp()` — the repair step that carries the ramp to an install that already
  exists. `ensureTaxonomy()` returns the moment the Category table has rows, so without this a
  change to the seed reaches only a brand-new install. It repaints **row by row, guarded on the old
  colour**, so a Category a clinic recoloured for itself survives untouched; that guard is pinned by
  `tests/integration/RampRepaintTest.php` against a real database.
- `tools/ramp-check.py`, run by `make lint`. It reads the colours the app actually ships — out of
  the PHP seed and out of `src/taxonomy.js` — rather than holding a copy that could agree with
  itself while the app disagreed with both. It gates on contrast, on normal-vision separation, and
  on a catastrophe floor under each of the three dichromacies.
- `docs/design/map-legibility.md`, the working-out behind issues #20–#26, and
  [ADR-0013](docs/adr/0013-hue-groups-categories-the-glyph-identifies-them.md) for the decision
  inside it that binds.

### Vocabulary

- **Sector** now says what it is *for*: it divides the population as much as the ground, and each
  one has a fixed team so the same families are seen by the same people. That purpose is what makes
  a Sector the figure and a Unidad Vecinal the ground, and it was nowhere in the repo.
- **Unidad Vecinal** now says it is a unit of *community* organisation — the basis on which a Junta
  de Vecinos is formed — that the clinic uses as **reference**, not as a working division.

### Known gap

`ensureRamp()` repaints `territorio_category` only. Every Sector **already drawn** keeps the colour
stored on its own row, including the old `#2563eb` default — so those are now saturated blue on a
deliberately quiet map. Issue #21 owns it.

### Notes

This is **not** a colour-blindness fix and is not claimed as one. The new ramp collapses more pairs
under protanopia and tritanopia than the one it replaces, and fewer under deuteranopia; the numbers
are in the ADR. No palette makes eleven categories safe on hue — the glyph does, and that is #20.

## [0.22.7] — unreleased

Writing down what a Sector runs along.

### Added

- A Sector keeps its **Limit**: the Boundary features it was built from, by name, plus any
  stretches somebody types in because no dataset holds them. Shown as one sentence — "Va por
  Vicuña Mackenna, el canal y por detrás del colegio" — with each part removable.
- The Limit comes back when the Sector is reopened, and survives adjusting the shape by hand.
- It appears in the GeoJSON and CSV exports as that sentence. Not in KML, which carries only a name
  and a location and says so in the file.
- The GeoJSON export writes it but the import does not read it back — the only field that does not
  round-trip, and deliberately: its parts name Boundary features by id, which mean nothing in
  another install, so reading the sentence back would replace a Limit built from a dozen picked
  streets with one line of prose.

### Notes

- **The Limit records intent; the geometry records the agreed line.** They match when a Sector is
  generated and stop matching the moment a vertex moves, which is expected rather than drift to be
  corrected. Nothing compares them or tries to keep them in step.
- Names are stored beside the ids, not looked up later. The lines are loaded only while the editor
  is open and only for what is on screen, so a Limit holding bare ids would read as blanks the
  moment somebody scrolled away — or after the Boundary Dataset was re-imported.
- A repeated name is said once, and listed once. A comuna holds one street as dozens of ways, so
  picking "Vicuña Mackenna" records dozens of parts — one row per name, with the count beside it,
  and removing the row removes all of them.
- A Sector drawn entirely by hand has no Limit at all, and that is valid (ADR-0007).
- Omitting the Limit from a save leaves it alone, like every other optional field. That is what
  makes it survive hand-adjustment: a client dragging a vertex must not have to remember to send
  the Limit back unchanged in order to keep it.
- The column is `boundary_limit`, not `limit`: the latter is reserved in every database this could
  run on, and a column needing quotes everywhere is one somebody eventually forgets to quote.

## [0.21.8] — unreleased

Building a Sector from the streets around it.

### Added

- **Generar el polígono**, in the Limit picker once something is picked: click inside the block and
  the picked lines become a Sector polygon, straight into the ordinary editor with every vertex
  draggable.
- Ordering, ring closure and repeated names need no intervention. The picked lines are cut into
  every enclosed face they make and the one containing the click is kept, which disposes of all
  three at once rather than solving them one at a time.
- When the lines do not close around the point, it says so and nothing changes. Hand-drawing stays
  available throughout (ADR-0007).

### Notes

- The generated polygon is a starting point, never the final answer. Real limits run along a canal,
  the edge of a park, or a line two teams agreed on years ago — none of which is in any dataset.
- The seed click is the person's, and cannot be guessed. Four streets crossing make several blocks,
  and the centre of the picked lines is outside the Sector whenever its limit runs along an L of two
  avenues.
- Nested faces resolve to the smallest one containing the click, so a Sector inside a ring of picked
  streets is the Sector rather than everything around it.
- The shape of each line is kept the moment it is picked. The list of lines on screen is replaced
  every time the map settles and capped at three thousand rows, so building a Sector from four
  avenues — which means panning along them — otherwise lost picks silently.
- `@turf/polygonize` and `@turf/boolean-point-in-polygon` are new dependencies. Polygonizing by hand
  is a graph traversal with an edge case for every way a street can join another one.

### Fixed

- Two integration tests asserted a record's Sector was `null` after a boundary moved away from it.
  That is a claim about every Sector on the instance, not about the one that moved, and the browser
  gate broke it the moment it started building four-kilometre Sectors. They now assert the record
  let go of *that* boundary. The gate retires its own Sector at the end of a run.

## [0.20.7] — unreleased

Taking the filtered set out of the app.

### Added

- **Exportar**, beside the search, exporting exactly what the list is showing — layers and search
  applied. GeoJSON (complete, and it goes back in through import), CSV (for a spreadsheet) and KML
  (name and location only).
- An export carrying contact details asks for the user's own password, using Nextcloud's
  `PasswordConfirmationRequired(strict: true)` rather than any credential invented here, and is
  recorded with who, when, which format and how many records (ADR-0006). An export carrying none
  asks for nothing, because a prompt that appears every time teaches people to type their password
  without reading why.
- An administrator reads the export log in **Administración → Territorio**: who, when, which
  format, how many records and what the export was of. It is the only route in the app that is not
  open to every user, because it is the list of who took personal data out of the building.

### Notes

- The export log is its own table, not a Revision (ADR-0012). `territorio_revision` IS the change
  cursor every open browser polls, and an export changes nothing — logging one there would make
  every open map in a clinic refetch for a change that does not exist.
- The confirmed and unconfirmed exports are two routes, not one route with a flag.
  `PasswordConfirmationRequired` is an attribute on a method, so a single endpoint deciding for
  itself would put the whole protection behind an `if` that a later edit can invert.
- KML carries no contact details whatever is asked for, and says so inside the file rather than
  only in the dialog — the file is what gets emailed to somebody who never saw the dialog.
- The CSV starts with a byte-order mark. Excel does not ask about encoding, it guesses Latin-1, and
  "Ñuñoa" opens as mojibake on the machines this file is actually opened on.
- Re-importing a GeoJSON export updates rather than duplicates, and every field a person can see
  survives. It does count as one update: an import rewrites `official`, the record of what the
  authority published, and an export of ours is not the authority.
- A CSV gives latitude and longitude only for a single point. A Sector is a polygon and its centre
  is not where the Sector is.
- A GeoJSON exported WITHOUT contact details cannot be imported back. Its contact fields are absent
  rather than empty, and an import reads absence as deletion — re-importing one would clear a
  comuna's telephone numbers, one silent "Actualizado" per record. The file says it is partial and
  the reader refuses it.
- A CSV value beginning with `=`, `+`, `-` or `@` is written as text. Excel executes such a cell
  when the file is opened, every user can edit every record (ADR-0005), and the formula would run
  on the machine of whoever opened the export rather than whoever typed it.
- A KML cannot be exported "with contact details" at all. It carries none, so the request can only
  be a mistake, and honouring it would mean a password prompt for a file with nothing personal in
  it — the unearned prompt ADR-0006 exists to avoid.

## [0.19.4] — unreleased

Two records that might be one place, decided by a person.

### Added

- A **Posibles duplicados** pane listing candidate pairs, with the outstanding count on the button that
  opens it. Each pair says why it was offered, shows both records side by side, and offers three
  answers: keep this one, keep that one, or they are not duplicates.
- Import flags candidate pairs and merges nothing (ADR-0011). Two shapes are detected, both of
  which appear in the real data: records at the same coordinate under different names, and similar
  names within a short distance of each other.
- Merging retires the loser and records a pointer to the survivor, which is the mapping
  `fonasa_eliminados.csv` was keeping by hand. Restoring the record undoes the merge and puts the
  pair back in the queue.
- `Fusionado` is its own entry in history, separate from `Retirado`: a merged record was not
  withdrawn, it was decided to be the same place as another one.

### Notes

- No auto-merge, at any threshold (ADR-0011). A pair left in a queue is visible and harmless; a
  wrong merge fails silently and is discovered by somebody phoning the wrong place.
- A dismissed pair never comes back. Re-offering it after the next import would teach people to
  close the queue and leave it closed.
- Detection compares Places only. A Sector has an extent rather than a coordinate, so "the same
  place" would mean overlapping areas — a different question, and one that would offer every
  Sector against the Unidad Vecinal it covers.
- Similar names count only within about 150 m. "Sede Junta de Vecinos" is thirty different
  buildings in La Florida, and pairing them all is 435 questions nobody will ever read.

## [0.18.8] — unreleased

Saying where a Sector's limit runs.

### Added

- While a Sector is open, the comuna's streets, canals and railways load for the view on screen and
  can be picked — by clicking a line, or by typing a name. Both land in the same selection, which
  is listed and individually removable. They unload when the editor closes.
- `GET /boundaries` returns the Boundary features overlapping a box, and only that: they are absent
  from every other read because a comuna is around eleven thousand lines (issue #12).

### Notes

- The app never resolves a street NAME (ADR-0007). Typing offers every name that matches and picks
  none of them: "Los Toros" and "Pasaje Los Toros Norte" are two rows, and choosing between them is
  the person's job, in one click, rather than a geocoder's guess.
- Names are grouped, because a comuna holds one street as dozens of separate ways. Picking the name
  picks all of it, and the count says how much that is — thirty identical rows is not a choice
  anybody can make.
- A line picked and then panned away from stays picked. It just cannot be named until it is loaded
  again, and dropping it would silently unpick it.
### Fixed

- The map no longer stops drawing after zooming. Its Leaflet handles lived in `data()`, so Vue 3
  wrapped every one of them in a reactive Proxy — and Leaflet's event bus identifies a listener by
  its context object, so a layer registered raw and unregistered through a Proxy left a handler
  pointing at a layer whose map was already gone. The next zoom threw and the map went stale. This
  had been reported and mis-diagnosed three times; nothing there was ever rendered, so none of it
  needed to be reactive.
- `tools/browser-check.py` now zooms and pans with the Limit picker open. The fault above only
  appeared about one run in three, because a redraw has to land inside a view change to create the
  broken handler — but once created it survives, so churning first and zooming after makes it show
  every time. Verified by reintroducing it: three runs, three failures.

### Notes

- Zoom animation stays off. It was a guess at the fault above rather than its cause, and it is kept
  because this map still rebuilds layer groups from three separate causes and an animated zoom is
  the widest window for one to land in.

## [0.17.1] — unreleased

Where a record falls, worked out rather than typed.

### Added

- A Place's Sector and Unidad Vecinal are resolved from its coordinate and stored (ADR-0008).
  Ray-casting point-in-polygon in about forty lines of PHP with a bounding-box pre-check — no
  PostGIS, which is not in the database image, no GEOS, no geometry library.
- Redrawing a boundary reassigns everything it gained and everything it lost, each as an ordinary
  write with its own Revision and history entry. ADR-0008 asks for exactly that: the reassignment
  is the point, and it has to be visible.
- The sidebar shows a record's Sector and Unidad Vecinal and offers nowhere to type them.
- `RegistryService` counts by Sector and by Unidad Vecinal, which is the half issue #18 had to
  leave until something stored an assignment.

### Notes

- A boundary that arrives AFTER the records takes them on. That is the likeliest
  order in practice — a comuna's Unidades Vecinales land after its catastro does — and without it
  every one of those records would sit assigned to nothing.
- Retiring a boundary lets go of everything it held, and restoring it takes them back. A record
  keeping an id that points at a retired Sector would be assigned to something the app will not
  show, and the sidebar would call it unassigned.
- A point on a boundary counts as inside. Ray casting alone answers that differently depending on
  which way the ray leaves, and a record on the line between two sectores belongs to one of them
  rather than to neither — "neither" is the answer that looks like a bug to whoever finds it.
- Renaming or recolouring a boundary reassigns nobody and spends no Revisions. Only a moved shape
  can change an answer.
- Null is a real answer, twice over: a record with no coordinate, and one outside every boundary
  the install holds. The catastro this app inherits has plenty of both.

## [0.16.0] — unreleased

The comuna, and the Unidades Vecinales that describe it.

### Added

- Setup asks which Comuna the install serves — exactly one, by CUT code, refused unless it is five
  digits. A Territorio is always a portion of one Comuna and never reaches into a second.
- `tools/comuna-cut.php` cuts the national `UnidadesVecinales_2024v4` file down to one comuna,
  keyed on `t_id_uv_ca`. The national file is 128 MB and 6,887 polygons across 346 comunas; one
  comuna is under 100 KB, and **the app must never need the national one**.
- A comuna's Unidades Vecinales import as a Cadastral Dataset and toggle beside the Category tree,
  like Sectores. They are read from the cadastre, never drawn.
- A file cut for another comuna is refused by name, before anything is written.
- The map opens on the comuna the install serves, measured from the Unidades Vecinales it holds,
  instead of a fixed guess at central Santiago.

### Fixed

- **Clicking the map to place a record no longer selects a boundary instead.** Once a comuna's
  Unidades Vecinales are imported they tile the whole view, so every click landed on one and handed
  the next save to the cadastre — one was renamed this way while the browser check ran. A click
  belongs to the open draft whenever one is waiting for a coordinate.
- **The map no longer empties itself.** Rebuilding the marker layer emptied a cluster group the map
  was still using, so a redraw landing inside a zoom or a pending reposition left Leaflet holding
  markers whose map was gone — it threw, and the pins did not come back. The group is now swapped
  whole. Zoom animation is off for the same reason: a smooth zoom is not worth a map that blanks.

### Notes

- The cutter converts a `.shp` with `ogr2ogr`, which is GDAL's job and not a dependency of this
  app — it runs once, on whoever cuts the file. Hand it a `.geojson` and it skips that step. The
  conversion is the one part no test here covers; `cutComuna()`, where every decision lives, is pure
  and tested.
- Verified end to end on a dev instance with an invented national file of two comunas: 37 Unidades
  Vecinales cut and imported, a file for another comuna refused by name, and a re-import spending
  no Revisions at all.

## [0.13.0] — unreleased

KML.

### Added

- KML imports through the same pipeline as GeoJSON: same Dataset, same batch, same derived ids,
  same revert. Points, lines and areas all arrive.
- A Folder name becomes the Category — and where the folder names a Subcategory instead, that is
  found too. Both matter for the real file: without the first, every record lands unclassified
  while its Folder names the right Category; without the second, "Ciclovías" and "Plazas y parques"
  alone leave 309 of 865 records, 36% of the export, in Sin clasificar while `ciclovia` and `plaza`
  sit in the seed.
- The import dialog says what KML cannot carry **before** the import runs, while a person can
  still choose a different file.

### Notes

- KML does not become rows of its own. It becomes a GeoJSON FeatureCollection and is then read by
  the reader that already existed, so the two formats cannot drift into importing differently.
- Checked against the real `La_Florida_Google_MyMaps.kml`: 865 placemarks, not one `ExtendedData`
  element between them. So no phone, email, contact person, opening hours or source — the warning
  is a fact about the format, not a caution.
- Parsed with SimpleXML, which PHP already has. A library to read five tag names would be a
  dependency for nothing.
- Placemarks are matched by local name, because KML files do not agree on a namespace: Google Earth
  still writes `earth.google.com/kml/2.1`, and naming the OGC one alone found nothing in those and
  told the person their file was empty.
- A named Placemark whose geometry cannot be read still becomes a record, with no coordinate. That
  is a valid state here, and dropping the row silently is what the batch's counts exist to prevent.

## [0.12.0] — unreleased

An answer for the app next door.

### Added

- `OCA\Territorio\Service\RegistryService` — counts and record lookups for other apps on this
  instance, by injection. No OCS route and no external API, and two tests keep it that way: one
  refuses an `ocs` section in the route file, another refuses a `Db\` class in any controller.

### Changed

- The import dialog's batch list and the page's opening cursor moved out of their controllers and
  into the services that own them. A controller that reads the database itself is a controller an
  OCS version would have to copy rather than replace.

### Notes

- The service reads through `FeatureMapper::findAll()` rather than querying for itself, so what it
  answers and what the app shows cannot drift: retired records are out, and so are the
  Boundary-feature Datasets.
- Counting records **by** the Sector or Unidad Vecinal they fall in is not there yet — ADR-0008
  derives that from geometry and stores it, and nothing stores one until issue #11. Counting the
  sectores themselves already works: they are Features under `division_territorial`.

## [0.11.0] — unreleased

Search.

### Added

- A search box narrows the Datos list, the map and the Análisis counts together, because it is a
  predicate on the one filtered set rather than a mechanism beside it. Case- and accent-insensitive:
  "castanos" finds "Los Castaños". It matches name, address, contact person and the
  "Categoría · Subcategoría" label, and it composes with the Capas toggles in either order.

### Notes

- Search reaches the retired list, where the Capas toggles deliberately do not. A toggle is a
  background setting someone forgot they left on; a query is someone saying what they are looking
  for, and ignoring it in the list they are staring at would overrule them.

## [0.10.0] — unreleased

Guardar works. It never had.

### Fixed

- The save button submits the form. `FeatureDetail.vue` passed `native-type="submit"` to `NcButton`
  — the @nextcloud/vue **8** prop name. Under 9 it is `type`, so the prop fell through as an inert
  HTML attribute, the button stayed a `type="button"`, and a `type="button"` inside a form does not
  submit it. `@submit.prevent` never fired and `App.vue::save()` was unreachable from the UI. It had
  been that way since the first Places slice: **not one record in this app's history had ever been
  written from a browser** — every revision carried a null actor, meaning `occ` or a test.
- A refusal that happens before a draft exists is now visible. `startSector()` wrote its error into
  the sidebar, which only renders while a draft is open, so a failed draw removed the polygon and
  said nothing at all.

### Added

- `tools/browser-check.py` — drives the running app with Playwright: creates a Place through the
  form, draws and saves a Sector, reloads, and asserts both survived. It checks the Registry rather
  than the screen, because a green screen over an empty table is the failure it exists to catch.
- A static guard for props @nextcloud/vue 9 no longer has. Vue passes an unknown prop straight
  through as an attribute, so a stale name is silent — no warning, no build error.

### Notes

- Three suites, a webpack build and a two-axis review on every slice passed for eight slices while
  the app's primary action did nothing. None of them loads a component or opens the app. That is the
  gap the browser check closes.

## [0.9.1] — unreleased

The comuna's streets, canals and railways — in the Registry, off the map.

### Added

- `tools/boundary-features.php` produces a comuna's Boundary features as GeoJSON from a bounding
  box. A repo-side tool run by hand, never a call the app makes (ADR-0004).
- `occ territorio:import … --boundary` marks the Dataset as boundary features, which takes the
  whole collection out of every ordinary read at once: the map, the Datos list, the Análisis
  counts and the change feed.
- Import accepts every geometry the Registry can store, not Points alone. What is left over —
  a MultiPolygon, say — is still counted rather than refused, so one odd row cannot make a
  cadastre of thousands an all-or-nothing import.

### Notes

- Named lines only, and the name filter earns its place twice over: for La Florida it is the
  difference between 11,178 ways and 19,034, the rest being service roads and driveways nobody
  bounds a sector with — and the import refuses a nameless row outright, so an unnamed way would
  abort the whole file rather than be skipped.
- Ids are OSM's own (`way/12345`) rather than the digest of name and position the reader derives
  otherwise. A street renamed or redrawn upstream therefore updates the record it already has,
  which is what makes a refresh months later safe — and no Sector already drawn is touched by one.
- Boundary features are filed under "Sin clasificar · Otro" deliberately. They are never drawn and
  never toggled, so what they are is said by the Dataset they belong to; seeding a taxonomy row
  for eleven thousand invisible records would put a branch in Capas that means nothing to anyone.

## [0.8.0] — unreleased

Sectores, drawn by hand.

### Added

- A Sector is drawn on the map with the polygon tool, named however the clinic names things, given
  a colour of its own, and saved. It renders as a light translucent fill under a clear outline,
  beneath the pins standing on it.
- Vertices snap to boundaries already on the map while drawing, so adjacent sectores share an
  exact edge instead of leaving slivers between them. Pins are excluded: a limit runs along a
  street, a river or another Sector, never through the middle of a school.
- An existing Sector reopens editable: select it and drag its vertices. The edit is an ordinary
  change, so it appears in history like any other.
- Sectores have their own toggle in Capas, beside the Category tree rather than inside it.

### Notes

- A Sector is a Feature, not a table of its own ([ADR-0010](docs/adr/0010-a-sector-is-a-feature-not-a-table-of-its-own.md)):
  it gets history, retirement, the change cursor and the revert without any of them being written
  a second time.
- This is the fallback path of ADR-0007 and it ships first. When the street picker arrives it
  produces a starting polygon and hands it to exactly this editor — hand-drawing stays fully
  supported, never second-class.
- Zones and Routes are no longer refused on the way in. The restriction was only ever "nothing
  draws one yet"; the geometry code has measured polygons correctly all along.
- Snapping works against whatever is on the map, so it will cover Unidad Vecinal boundaries as
  soon as they are imported — they are Features drawn by the same code.

## [0.7.3] — unreleased

### Fixed

- The record list came back. `listFor` was called in `App.vue` without being imported, so the
  computed behind the Datos column threw a `ReferenceError` and took the list down with it —
  present since the Capas panel landed. Nothing catches this class of mistake yet: the three
  suites test `filters.js` directly and never load `App.vue`, and there is no JS linter.

## [0.7.2] — unreleased

Undo an import.

### Added

- Recent runs are listed in the import dialog with who ran them and what they did, each with a
  **Deshacer**. Undo lives beside the thing it undoes.
- Reverting retires what a run created and puts back what it overwrote — and leaves alone anything
  touched since, reporting how many it left.

### Notes

- A revert is expressed as the inverse of each action the batch recorded, applied newest first:
  created becomes retired, an update is restored to its previous values, a retirement is undone.
  Expressing it that way rather than as an "undo import" routine is what makes a revert revertible
  — it records its own batch, its Revisions are stamped with it, and undoing THAT is the same code
  walking a different batch.
- A revert never marks a record as a local override. Going through the ordinary edit path would,
  and every future import would then skip those records for good (ADR-0003) — a strange and
  permanent consequence of pressing undo.
- Nothing is undone blindly. A record with a newer Revision than the batch's has been touched
  since, so it is left exactly as it is: a revert exists to prevent silent loss, not to cause it.

## [0.6.0] — unreleased

GeoJSON import.

### Added

- `occ territorio:import <archivo> --dataset=<slug>` and a browser upload, both through one
  service so they cannot drift.
- Datasets (Cadastral or Curated), and Import batches recording actor, moment, Dataset and the
  Revision range the run produced — the range ADR-0009's revert depends on and that nothing can
  reconstruct afterwards.
- Provenance on Features: which Dataset a record came from, the external id a re-import matches
  on, and the official values kept beside a local edit.

### Notes

- Developed against the real 865-feature La Florida catastro to learn what real files look like:
  538 would be created, 18 rows are exact duplicates, 309 are kinds nothing draws yet, and
  re-importing moves the Revision cursor by zero. That data is NOT loaded — the dev instance holds
  invented records only.
- Matching is on `(Dataset, external id)`. The real file carries no `id` and no `uid` — checked,
  not assumed — so ids are derived from source, name and position, which is what makes a second
  import update instead of creating 865 more records.
- The file repeats 18 rows verbatim, inherited from FONASA. Collapsing them is right; doing it
  silently is not, so the count is reported.
- Nothing is written until the whole file has been read and checked. One bad coordinate refuses
  the entire import — a half-imported catastro is far harder to reason about than a refused one.
- A record someone edited, or retired, is never overwritten (ADR-0003). The authority's latest
  values are still refreshed alongside, so "official vs local" stays current and the override can
  be undone.

## [0.5.0] — unreleased

The Capas panel, and one filtered set behind everything on screen.

### Added

- The Category tree as toggles in the app navigation. Unchecking a Category takes its whole branch
  with it; unchecking one Subcategory leaves its siblings alone. The parent checkbox shows an
  indeterminate state when only part of the branch is hidden, and clicking it then reveals the
  rest rather than hiding what was left.
- An Análisis view: counts by Category and Subcategory over the filtered set, drawn as bars.
- A JS test runner (vitest) and `make test-js`. The filter logic is a pure module with 17 tests —
  the first frontend logic in the repo that is tested rather than eyeballed.

### Notes

- The filter records what is **hidden**, never what is visible. That is what makes a Subcategory
  added to the taxonomy appear on its own with no code change: it is absent from the hidden set,
  so it shows. Storing visible ids would leave every new row invisible until some code went
  looking for it.
- The map, the Datos list and Análisis all derive from one filtered set rather than filtering
  separately. Three filters is how a count ends up disagreeing with what is on screen.
- Análisis counts what it is handed and filters nothing itself, for the same reason.
- The filter is a View (CONTEXT.md): it lives in the browser, is never sent anywhere, and changing
  it changes nothing for any other user.

## [0.4.0] — unreleased

History and retirement.

### Added

- Every change appends a history entry naming who did it, when, and what changed — including what
  the field used to say. Those previous values are the point, not decoration: ADR-0009 says an
  import revert "restores what it overwrote", and this is where that comes from.
- Deleting a record retires it (ADR-0005). It leaves the map and the list, keeps its history, and
  can be restored intact. (There are no counts to leave yet — Análisis is issue #5.)
- A record's history is shown in its sidebar; retired records are browsable and restorable from
  the list.

### Notes

- The history columns went onto `territorio_revision`, the log that already carried the Revision
  and the moment. Adding the actor, the action and the diff to those rows is what ADR-0002 and
  ADR-0005 meant by one mechanism rather than two.
- `findChangedSince()` is the one read that does NOT hide retired records, deliberately. A client
  already holding a record has to be told it was retired so it can stop drawing it; filtering it
  out of the change feed would leave it on every open map until someone reloaded.
- Retiring is confirmed inline, in two steps, never with a browser `confirm()` — that blocks the
  page, and there is nothing irreversible to warn about.
- `Retired` and `Restored` are distinct actions rather than an ordinary diff of a boolean, because
  "who retired this?" is a question history exists to answer and should not need reading every
  entry to find.

## [0.3.0] — unreleased

Collaboration: one shared map that keeps itself current.

### Added

- A monotonic Revision stamped on every change, issued from a single locked row. Deliberately not
  an autoincrement: that guarantees two writers get *different* numbers, which is not what a cursor
  needs — it needs numbers to become visible in the order they were issued. With an autoincrement,
  writer A can take 5, writer B take 6 and commit first, and a client polling in between banks 6
  and never asks for 5 again.
- `GET /changes?since=N` — the cursor every open map polls. When nothing has changed it answers
  from a single indexed read and never touches the Feature table, which is what makes a
  five-second poll from every open map affordable on an instance with no push backend (ADR-0002).
- Client polling roughly every five seconds, stopping entirely while the tab is hidden and
  catching up in one request when it returns.
- The Revision log is already the change log: issue #4 adds the actor and the action to these same
  rows rather than introducing a second counter.

### Notes

- Stamping happens in `FeatureMapper::insert()/update()`, inside one transaction, and neither half
  is optional. The cursor is correct only as long as no write escapes without advancing it, and
  only as long as the Revision and the row become visible together — a Revision landing first lets
  a poll bank a cursor past a change it was never given. `RevisionLog::allocate()` refuses outside
  a transaction so that window cannot be constructed.

- Seeding runs from a repair step, not a migration's `postSchemaChange`. That hook is skipped when
  a schema is applied on a first install, so seeds written there run on every upgrade and on no
  fresh install — the one case that matters most, and the one a developer upgrading their own dev
  instance never sees.
- The page reads the Revision *before* the Features, deliberately. The other order lets a change
  landing between the two reads be absent from the list yet already counted in the cursor, so the
  client would never ask for it and would stay stale forever. This way it is simply delivered
  again, and merging a record twice is harmless.
- Not SSE and not WebSockets: this instance runs `nextcloud:34-apache`, where a held-open
  connection pins a PHP worker.

## [0.2.0] — unreleased

Places: the Registry's first records.

### Added

- The Category/Subcategory taxonomy, seeded with the eleven categories and the subcategories the
  La Florida catastro actually used. It is editable data, not code — adding a row makes it appear
  in the app.
- Features stored as GeoJSON text with a bounding box in four plain columns. No PostGIS is
  available, and a Nextcloud app must run on MySQL, SQLite and Postgres alike.
- Datos: a list of records beside the map, with a sidebar to add and edit one. Selecting a record
  highlights its marker; markers cluster and are coloured by Category.
- A record with no coordinate is valid — it appears in Datos and not on the map. The inherited
  catastro holds real organisations whose address never resolved.
- Kind and the bounding box are derived from the geometry and never accepted from the client.
  Location quality is forced to "absent" when there is no geometry to describe.
- An integration suite that round-trips the mappers against a real Postgres.

### Notes

- `FeatureMapper::delete()` refuses outright. ADR-0005 forbids hard-deleting a Feature anywhere in
  the app, and QBMapper hands every subclass a public `delete()`, so the mapper is where that rule
  has to be enforced rather than merely written down. Retirement arrives with history in issue #4;
  until then a Feature cannot be removed at all, which is the safe direction to be wrong in.
- Only Point geometry is accepted. Zones and Routes are measured correctly but nothing draws them
  yet, so one would appear as a single pin on an arbitrary vertex. Lifted in issue #10.
- An update only writes the fields it was sent. Omitting one leaves it alone; clearing it means
  sending it empty.

## [0.1.0] — unreleased

First slice: the app shell and the map. No territorial data yet.

### Added

- Nextcloud 34 app that appears in the navigation and opens to a full-height Leaflet map.
- Administrator setting for the tile URL and attribution, so a clinic without internet access can
  repoint the basemap at a self-hosted tile server with no code change (ADR-0004).
- The Content-Security-Policy image host is derived from the configured tile URL, so the policy
  cannot drift from the tiles it exists to allow. Leaflet's `{s}` subdomain placeholder becomes a
  CSP wildcard; ports are preserved.
- A tile URL that is not a usable template is stored as typed — so the administrator can see and
  correct it — but never served, and never reaches the response header.
