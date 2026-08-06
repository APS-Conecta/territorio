# Changelog

All notable changes to Territorio are recorded here. Decisions behind them live in
[`docs/adr/`](docs/adr/).

## [0.3.0] — unreleased

Collaboration: one shared map that keeps itself current.

### Added

- A monotonic Revision stamped on every change, allocated by a dedicated table whose autoincrement
  id *is* the Revision — the one portable way to guarantee that two people saving in the same
  instant get different numbers across MySQL, SQLite and Postgres.
- `GET /changes?since=N` — the cursor every open map polls. When nothing has changed it answers
  from a single indexed read and never touches the Feature table, which is what makes a
  five-second poll from every open map affordable on an instance with no push backend (ADR-0002).
- Client polling roughly every five seconds, stopping entirely while the tab is hidden and
  catching up in one request when it returns.
- The Revision log is already the change log: issue #4 adds the actor and the action to these same
  rows rather than starting a second sequence.

### Notes

- Stamping happens in `FeatureMapper::insert()/update()`, not in the service. The cursor is only
  correct as long as no write escapes without advancing it, and a rule that lives in a service is
  one the next caller can walk around — the same reasoning that makes `delete()` throw. An
  integration test writes through the mapper directly to prove it.
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
