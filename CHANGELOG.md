# Changelog

All notable changes to Territorio are recorded here. Decisions behind them live in
[`docs/adr/`](docs/adr/).

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
