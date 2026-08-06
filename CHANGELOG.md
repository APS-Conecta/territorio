# Changelog

All notable changes to Territorio are recorded here. Decisions behind them live in
[`docs/adr/`](docs/adr/).

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
